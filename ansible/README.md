# Ansible GCP Deployment

This guide covers deploying to GCP Compute instances using GitHub Actions and Ansible.

## Prerequisites

### GCP Setup

1. **Service Account** with the following roles:
   - `roles/compute.instanceAdmin.v1` - Manage instances
   - `roles/compute.securityAdmin` - Manage firewall rules
   - `roles/iam.serviceAccountUser` - Use service accounts
   - `roles/compute.osLogin` - OS Login access

2. **Workload Identity Federation** configured for GitHub Actions authentication

3. **Compute Instances** with:
   - External IP addresses assigned
   - Network tags matching `INSTANCE_GROUP_NAME`
   - OS Login enabled (default)

### GitHub Secrets

Configure these secrets in your GitHub repository (Settings > Secrets > Actions):

| Secret | Description | Example |
|--------|-------------|---------|
| `GCP_PROJECT_ID` | Your GCP project ID | `my-project-123` |
| `GCP_REGION` | GCP region for instances | `us-east1` |
| `GCP_SERVICE_ACCOUNT` | Service account email | `deploy@my-project.iam.gserviceaccount.com` |
| `GCP_WORKLOAD_IDENTITY_PROVIDER` | Workload Identity provider | `projects/123/locations/global/workloadIdentityPools/github/providers/github` |
| `INSTANCE_GROUP_NAME` | Instance name pattern/tag | `kanvas-dev` |
| `GCP_INSTANCE_APP_DIR` | App directory on instance | `/home/ubuntu/var/www/html` |

### Environment Configuration

Create a GitHub Environment matching your branch name (e.g., `development`, `production`). Add the secrets to each environment.

## How It Works

### SSH Access Flow

1. **Firewall Rule**: GitHub Actions fetches the first 250 GitHub Actions IP ranges from `api.github.com/meta` and creates/updates a firewall rule allowing SSH from those IPs.

2. **SSH Key Injection**: The workflow generates an SSH keypair, then uses `gcloud compute ssh` with OS Login to connect and add the public key to the `ubuntu` user's `~/.ssh/authorized_keys`.

3. **Ansible Connection**: Ansible connects directly via SSH as the `ubuntu` user using the generated private key.

### Deployment Flow

1. Authenticate to GCP via Workload Identity Federation
2. Create dynamic Ansible inventory from GCP Compute instances
3. Create/update firewall rule for GitHub Actions IPs
4. Inject SSH key to instances via OS Login
5. Run Ansible playbook

## Running the Workflow

The workflow is triggered manually via `workflow_dispatch`:

1. Go to **Actions** > **Ansible GCP Compute Deploy**
2. Click **Run workflow**
3. Select the branch (must match an environment name)
4. Click **Run workflow**

## Ansible Playbook

The playbook at `ansible/playbooks/{branch}-deploy.yaml` runs the deployment tasks:

```yaml
- name: Deploy app to all instances
  hosts: development  # Matches branch/environment name
  become: true

  tasks:
    - name: Sync app files
      ansible.posix.synchronize:
        src: ../../
        dest: "{{ app_dir }}"
        # ... excludes for .git, vendor, etc.

    - name: Install dependencies
      shell: /usr/local/bin/composer install --optimize-autoloader
      args:
        chdir: "{{ app_dir }}"
```

## SSH Access for Developers

### Option 1: OS Login (Recommended)

If you have access to the GCP organization:

1. Get the `roles/compute.osLoginExternalUser` role granted at the organization level:
   ```bash
   gcloud organizations add-iam-policy-binding ORGANIZATION_ID \
     --member="user:your-email@example.com" \
     --role="roles/compute.osLoginExternalUser"
   ```

2. SSH via gcloud:
   ```bash
   gcloud compute ssh INSTANCE_NAME --project=PROJECT_ID --zone=ZONE
   ```

3. Switch to ubuntu user if needed:
   ```bash
   sudo -u ubuntu -i
   ```

### Option 2: Metadata SSH Keys (If OS Login is disabled)

1. Disable OS Login on the instance:
   ```bash
   gcloud compute instances add-metadata INSTANCE_NAME \
     --project=PROJECT_ID \
     --zone=ZONE \
     --metadata enable-oslogin=FALSE
   ```

2. Add your SSH key:
   ```bash
   gcloud compute instances add-metadata INSTANCE_NAME \
     --project=PROJECT_ID \
     --zone=ZONE \
     --metadata ssh-keys="ubuntu:$(cat ~/.ssh/id_rsa.pub)"
   ```

3. SSH directly:
   ```bash
   ssh ubuntu@EXTERNAL_IP
   ```

## Firewall Rules

The workflow creates a firewall rule `allow-ssh-github-actions` that:
- Allows TCP port 22 (SSH)
- From the first 250 GitHub Actions IP ranges
- To instances with the configured network tag

**Note**: This rule is managed by the GitHub Actions workflow and recreated on each deployment. If you `terraform destroy` your infrastructure, this rule will be orphaned and need manual cleanup:

```bash
gcloud compute firewall-rules delete allow-ssh-github-actions --project=PROJECT_ID
```

## Troubleshooting

### Permission denied (publickey)

- Ensure the firewall rule was created successfully
- Check that the SSH key was added to `~/.ssh/authorized_keys` on the instance
- Verify Ansible is using `-u ubuntu` and the correct private key

### OS Login external user error

You need `roles/compute.osLoginExternalUser` at the organization level. Contact your GCP organization admin.

### Composer not found

The playbook uses `/usr/local/bin/composer`. If composer is installed elsewhere, update the path in the playbook or create a symlink on the instance.

### Firewall rule creation fails

The service account needs `roles/compute.securityAdmin` to create/delete firewall rules.

## File Structure

```
ansible/
├── README.md                    # This file
├── inventory.gcp.yaml           # Generated dynamically by workflow
└── playbooks/
    ├── development-deploy.yaml  # Development environment playbook
    └── prod-deploy.yaml         # Production environment playbook

.github/workflows/
└── ansible-gcp-deploy.yml       # GitHub Actions workflow
```
