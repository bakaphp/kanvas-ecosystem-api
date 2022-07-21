<?php

namespace Kanvas\Notifications\Templates;

use Illuminate\Support\Facades\Storage;
use Kanvas\Templates\Repositories\TemplatesRepository;

class Welcome extends Mail
{
    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('welcome-template');
    }
}
