# Creating new Workflows from Activities in Kanvas

# Activities and Workflows

## Activities

Activities correspond to actions executed by workflows. Often they only do one action but sometimes an activity can do make a variety of actions. Actions can be anything; from sending an email to a new user after registering to optimizing an image when a large size image is sent.

## Workflows

Workflows are series of activities that are triggered by specific conditions on the system and on an entity. For example: If a user has been registered on the system a workflow is triggered to send the new user a welcome email.

# Creating your first activity

The most basic template for an activity is the following:

![simple_activity.png](./resources/simple_activity.png)

# Registering you Activity

All activities are registered on the **KanvasWorkflowSynActionCommand** and are added to the actions list:

![actions_array.png](./resources/actions_array.png)

## Running the command to register the new activity in the Kanvas Database

Run the following command to sync the newly added activity with the Kanvas database:

```bash
php artisan kanvas:workflow-sync-actions
```

# Creating your workflow on Kanvas

To create a new workflow you just need to execute the command:

```bash
kanvas:create-workflow {app_id}
```

The command will walk you through a series of questions regarding the name, description, model of your workflow entity and the rules and actions associated with it for it to trigger.

![workflow_command_ui.png](./resources/workflow_command_ui.png)