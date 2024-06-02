<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class AgentsImportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-guild:import {apps_id} {company_id} {file}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import agents from a csv file';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        Bouncer::scope()->to(RolesEnums::getScope($app));

        $company = Companies::getById((int) $this->argument('company_id'));
        $file = $this->argument('file');

        //read csv file
        $csv = Reader::createFromPath($file, 'r');

        $csv->setHeaderOffset(0);

        $header = $csv->getHeader(); //returns the CSV header record
        $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        //validate header
        $fixedHeader = [
            'Record Id',
            'Agent Name',
            'Agent First',
            'Agent Last',
            'Owner Id',
            'Agent Owner',
            'Email',
            'Phone',
            'Member Number',
            'Sponsor',
        ];

        //validate header columns dont exist in header array
        foreach ($fixedHeader as $column) {
            if (! in_array($column, $header)) {
                $this->error('Column ' . $column . ' not found in csv file , please check the file follows the correct format');

                return;
            }
        }

        foreach ($records as $record) {
            $record['Record Id'] = str_replace('zcrm_', '', $record['Record Id']);
            $record['Owner Id'] = str_replace('zcrm_', '', $record['Owner Id']);

            if (empty($record['Sponsor']) || empty($record['Member Number']) || empty($record['Email'])) {
                continue;
            }

            try {
                $user = Users::getByEmail(trim($record['Email']));

                if (empty($user->phone_number) && ! empty($record['Phone'])) {
                    $user->phone_number = Str::sanitizePhoneNumber(str_replace('-', '', $record['Phone']));
                    $user->save();
                    $this->info('User ' . $user->email . ' updated successfully');
                }
            } catch (ModelNotFoundException|EloquentModelNotFoundException $e) {
                //create user
                $createUser = new CreateUserAction(
                    RegisterInput::fromArray(
                        [
                            'firstname' => $record['Agent First'],
                            'lastname' => $record['Agent Last'],
                            'displayname' => null,
                            'email' => $record['Email'],
                            'password' => Str::password(10),
                            'phone_number' => $record['Phone'],
                        ],
                        $company->defaultBranch()->first()
                    )
                );

                $createUser->disableWorkflow();
                $user = $createUser->execute();
                $this->info('User ' . $user->email . ' created successfully');
            }

            $agent = Agent::updateOrCreate(
                [
                    'users_id' => $user->getId(),
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                ],
                [
                    'name' => $record['Agent Name'],
                    'member_id' => $record['Member Number'],
                    'owner_id' => $record['Sponsor'],
                    'users_linked_source_id' => $record['Record Id'],
                    'owner_linked_source_id' => $record['Owner Id'],
                    'status_id' => 1,
                ]
            );

            $this->info('Agent ' . $agent->name . ' synced successfully');
        }
    }
}
