<br />
<p align="center">
<img src="https://cdn.prod.website-files.com/66c9f056ff6b7f7ba51cdf21/66ccb2a881e7036ab59136f2_Logo_Kanvas_3.png" alt="Kanvas Logo" style="width: 20%; height: auto;">
    <br />
    <br />
</p>

[![static analysis](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/static-analysis.yml)
[![CI](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml/badge.svg)](https://github.com/bakaphp/kanvas-ecosystem-api/actions/workflows/tests.yml)

Kanvas was born out of years spent building complex backend systems for modern commerce. As an agency, we kept running into the same problems — slow integrations, disconnected systems, repetitive workflows, and custom logic that had to be rebuilt for every project.

We realized there was a better way.

## **Enter Kanvas Niche.**
Kanvas is your operational engine — a modular backend designed to unify your systems, automate your workflows, and power the next generation of commerce applications.

Kanvas gives you APIs, workflows, and agent-ready infrastructure — so you can launch faster, integrate better, and scale smarter.

## **What Kanvas Niche Offers:**
- **Ecosystem**: Dive into authentication and teams (or multi-tenant) management.
- **Inventory**: Manage products, their variants, and distribution channels efficiently.
- **Social**: Engage with features like follows, comments, reactions, and messaging.
- **CRM**: Navigate through leads, deals, and pipelines with ease.
- **Workflow**: Seamlessly connect your app with other systems.

## **Built to Extend, Not Replace**
Kanvas isn’t trying to be your monolithic platform. It connects the stack you already use — NetSuite, Shopify, Salesforce, custom apps — and becomes your operational layer in the middle.

Unlike typical low-code automation tools, Kanvas is designed to be part of your core product architecture. Built with Laravel + GraphQL, it supports scalable APIs and deep system integrations.

## **Use Kanvas to Launch**
🛍️ Marketplaces – With built-in vendor, product, and order logic

🚘 Dealer platforms – CRM, inventory, and lead routing included

🧩 Product bundlers – Dynamic SKUs + inventory syncing

🏪 B2B commerce portals – Multi-user pricing, approvals, and logic

📱 B2C apps – Headless APIs to power custom frontends

## Prerequisites

- PHP ^8.4
- Laravel ^12.0

## Initial Setup

1. Use the ``docker compose up --build -d`` to bring up the containers. Make sure to have Docker Desktop active and have no other containers running that may cause conflict with this project's containers(There may be conflicts port wise if more than one container uses the same ports).

2. Check the status of containers using the command ```docker-compose ps```. Make sure they are running and services are healthy.

3. Get inside the database container using ```docker exec -it mysqlLaravel /bin/bash```. Then, create 7 databases: `inventory`, `social`, `crm`, `workflow`, `commerce`, `action_engine`, `event`.

4. Set up your .env: You can start by copying the `.env.example setup`. Next, update it with the database and Redis connection info, making sure that the host values match your container's name.

5. Get inside the php container using ```docker exec -it phpLaravel bash```.

6. Generate app keys with `php artisan key:generate`.
**Note:** Confirm that your app key is correctly registered in the `apps` table within the `kanvas_laravel` database.

7. Update the app variables in your .env `APP_JWT_TOKEN`, `APP_KEY`, `KANVAS_APP_ID` before running the setup-ecosystem.
**Note:** You can use the default values provided in `tests.yml`.

8. Use the command ```php artisan kanvas:setup-ecosystem``` to run the kanvas setup.

9. If you're presenting some errors after running the command from before, drop all the tables from the schema `kanvas_laravel` and run it again.

10. To check if the API is working just make a GET request to  ```http://localhost:80/v1/``` and see if the response returns ```"Woot Kanvas"```.

### Setup Inventory
1. composer migrate-inventory
2. Set env var in .env
```
DB_INVENTORY_HOST=mysqlLaravel
DB_INVENTORY_PORT=3306
DB_INVENTORY_DATABASE=inventory
DB_INVENTORY_USERNAME=root
DB_INVENTORY_PASSWORD=password
```

`php artisan inventory:setup` to create and initialize the inventory module for a current company

### Setup Social
1. composer migrate-social
2. Set env var in .env
```
DB_SOCIAL_HOST=mysqlLaravel
DB_SOCIAL_PORT=3306
DB_SOCIAL_DATABASE=social
DB_SOCIAL_USERNAME=root
DB_SOCIAL_PASSWORD=password
```

`php artisan social:setup` to create and initialize the social module for a current company

### Setup Guild
1. composer migrate-crm
2. Set env var in .env
```
DB_CRM_HOST=mysqlLaravel
DB_CRM_PORT=3306
DB_CRM_DATABASE=cr
DB_CRM_USERNAME=root
DB_CRM_PASSWORD=password
```


`php artisan guild:setup` to create and initialize the crm module for a current company

## Running the project with Laravel Octane

After doing all the steps above, you could run the project with Laravel Octane by using the command ```php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000```. 

Use `--watch` in development allowing you to refresh modified files, this works assuming to have `npm install chokidar` installed in the project.
****

## Deployment to GCP with Ansible

This project uses GitHub Actions with Ansible to deploy to Google Cloud Platform (GCP) compute instances using Workload Identity Federation for secure authentication.

### Architecture Overview

- **GitHub Actions**: Orchestrates the deployment workflow
- **Workload Identity Federation**: Secure authentication to GCP without storing service account keys
- **Ansible**: Automates deployment tasks across GCP compute instances
- **Dynamic Inventory**: Automatically discovers GCP instances based on naming patterns

### GCP Setup

#### 1. Create Workload Identity Pool

```bash
export PROJECT_ID="your-project-id"
export PROJECT_NUMBER=$(gcloud projects describe $PROJECT_ID --format="value(projectNumber)")
export REPO="bakaphp/kanvas-ecosystem-api"

# Create Workload Identity Pool
gcloud iam workload-identity-pools create "github-pool" \
  --project="${PROJECT_ID}" \
  --location="global" \
  --display-name="GitHub Actions Pool"
```

#### 2. Create OIDC Provider

```bash
gcloud iam workload-identity-pools providers create-oidc "github-provider" \
  --project="${PROJECT_ID}" \
  --location="global" \
  --workload-identity-pool="github-pool" \
  --display-name="GitHub Actions Provider" \
  --issuer-uri="https://token.actions.githubusercontent.com" \
  --attribute-mapping="google.subject=assertion.sub,attribute.actor=assertion.actor,attribute.repository=assertion.repository,attribute.repository_owner=assertion.repository_owner" \
  --attribute-condition="assertion.repository_owner=='bakaphp'"
```

#### 3. Create Service Account

```bash
# Create service account
gcloud iam service-accounts create github-actions-sa \
  --project="${PROJECT_ID}" \
  --display-name="GitHub Actions Service Account"

export SA_EMAIL="github-actions-sa@${PROJECT_ID}.iam.gserviceaccount.com"

# Grant necessary permissions
gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:${SA_EMAIL}" \
  --role="roles/compute.viewer"

# Grant OS Login permissions for SSH access (replaces SSH keys)
gcloud projects add-iam-policy-binding $PROJECT_ID \
  --member="serviceAccount:${SA_EMAIL}" \
  --role="roles/compute.osAdminLogin"

# Allow Workload Identity to impersonate the service account
gcloud iam service-accounts add-iam-policy-binding "${SA_EMAIL}" \
  --project="${PROJECT_ID}" \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/github-pool/attribute.repository/${REPO}"
```

#### 4. Get Provider Path

```bash
# Get the full provider path for GitHub secrets
gcloud iam workload-identity-pools providers describe github-provider \
  --workload-identity-pool=github-pool \
  --location=global \
  --project=$PROJECT_ID \
  --format="value(name)"
```

#### 5. Enable OS Login (Replaces SSH Key Management)

OS Login uses IAM for SSH authentication, eliminating the need to manage SSH keys. This is more secure and provides automatic key rotation.

```bash
# Enable OS Login at project level (applies to all instances)
gcloud compute project-info add-metadata \
  --metadata enable-oslogin=TRUE \
  --project=$PROJECT_ID

# Verify OS Login is enabled
gcloud compute project-info describe \
  --project=$PROJECT_ID \
  --format="value(commonInstanceMetadata.items.filter(key:enable-oslogin))"
```

**Benefits of OS Login:**
- No SSH keys to store in GitHub Secrets
- Automatic credential rotation via IAM
- Centralized access control
- Full audit logging via Cloud Audit Logs
- Works seamlessly with Workload Identity Federation

**For Managed Instance Groups:**
OS Login is automatically applied to all current and future instances when enabled at the project level. No changes to instance templates required.

### GitHub Configuration

#### Required Secrets

Go to: **Repository Settings → Secrets and variables → Actions → New repository secret**

| Secret Name | Value | Example |
|------------|-------|---------|
| `GCP_WORKLOAD_IDENTITY_PROVIDER` | Full provider path from step 4 | `projects/123456789/locations/global/workloadIdentityPools/github-pool/providers/github-provider` |
| `GCP_SERVICE_ACCOUNT` | Service account email | `github-actions-sa@your-project.iam.gserviceaccount.com` |
| `GCP_PROJECT_ID` | Your GCP project ID | `your-project-id` |
| `GCP_REGION` | Your GCP region | `us-central1` |
| `INSTANCE_GROUP_NAME` | Name pattern for instances | `development` |
| `GCP_COMPUTE_SSH_PRIVATE_KEY` | SSH private key for compute instances | (your SSH private key) |

#### Required Variables

Go to: **Repository Settings → Secrets and variables → Actions → Variables**

| Variable Name | Value | Example |
|------------|-------|---------|
| `SSH_USER` | SSH username | `ubuntu` or `your-username` |

#### GitHub Environments

Create environments matching your branch names (e.g., `development`, `staging`, `main`) in:
**Repository Settings → Environments**

This allows branch-specific configurations and deployment protection rules.

### Deployment Workflow

The deployment is triggered manually via `workflow_dispatch`. To deploy:

1. Go to **Actions** tab in GitHub
2. Select **"Ansible GCP Compute Deploy"** workflow
3. Click **"Run workflow"**
4. Select the branch to deploy
5. Click **"Run workflow"**

#### What Happens During Deployment

1. **Authentication**: Authenticates to GCP using Workload Identity Federation
2. **Dynamic Inventory**: Discovers running GCP instances in the specified region matching the instance group name
3. **File Sync**: Syncs application files to remote servers (excluding git, node_modules, vendor, etc.)
4. **Dependencies**: Installs Composer dependencies
5. **Migrations** (optional): Runs database migrations
6. **Cache**: Clears and rebuilds Laravel config/route/view cache
7. **Docker**: Restarts Docker containers with updated code

### Ansible Playbooks

Playbooks are organized by environment in `ansible/playbooks/`:
- `development-deploy.yaml`
- `staging-deploy.yaml` (if applicable)
- `production-deploy.yaml` (if applicable)

#### Playbook Structure

```yaml
---
- name: Deploy app to all instances
  hosts: development  # Matches the group created by dynamic inventory
  become: true
  strategy: free  # Run on all hosts in parallel

  tasks:
    - name: Sync app files
      ansible.posix.synchronize: ...

    - name: Install dependencies
      command: composer install ...

    - name: Redeploy docker containers
      docker_compose: ...
```

### GCP Instance Requirements

Instances must:
- Be named with the pattern matching `INSTANCE_GROUP_NAME` (e.g., `development-api-01`)
- Be in `RUNNING` state
- Have SSH access configured with the provided private key
- Have Docker and Docker Compose installed
- Have the application directory structure in place

### Troubleshooting

#### Authentication Errors

```
Error: invalid_target
```

**Solution**: Verify the Workload Identity Provider path and ensure pool/provider are ACTIVE:

```bash
gcloud iam workload-identity-pools describe github-pool \
  --location=global \
  --project=YOUR_PROJECT_ID \
  --format="value(state)"

gcloud iam workload-identity-pools providers describe github-provider \
  --workload-identity-pool=github-pool \
  --location=global \
  --project=YOUR_PROJECT_ID \
  --format="value(state)"
```

Both should return `ACTIVE`.

#### No Hosts Found

**Solution**: Check that:
- Instances are running
- Instance names contain the `INSTANCE_GROUP_NAME` value
- Instances are in the specified `GCP_REGION`

```bash
gcloud compute instances list \
  --project=YOUR_PROJECT_ID \
  --filter="name~INSTANCE_GROUP_NAME AND status=RUNNING"
```

#### SSH Connection Issues

**Solution**: Verify SSH key is correct and user has access:

```bash
# Test SSH connection manually
ssh -i path/to/private-key username@instance-ip
```

### Local Testing

Test the Ansible playbook locally:

```bash
cd ansible

# Test inventory discovery
ansible-inventory -i inventory.gcp.yml --list

# Run playbook with dry-run
ansible-playbook playbooks/development-deploy.yaml \
  -i inventory.gcp.yml \
  --check \
  --diff
```

## Working with kanvas
- [Coding guideline](https://github.com/bakaphp/kanvas-ecosystem-api/wiki/Coding-Guidelines)
- [Wiki](https://github.com/alexeymezenin/laravel-best-practices#follow-laravel-naming-conventions)
- [TypeScript SDK](https://github.com/bakaphp/kanvas-core-js)
- [Documentation](https://github.com/bakaphp/kanvas-doc)

Note: 
- To install Swoole you can use the command ```pecl install swoole``` 
- For production remove `--watch` from the command.
- roles_kanvas_legacy will be deleted in the future
