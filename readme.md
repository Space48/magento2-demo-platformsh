# Magento 2.x Demo Store on Platform.sh

This repository contains the code and configuration required to deploy Magento 2.x to a platform.sh environment.

The admin interface can be accessed at `/admin` with the following credentials:

* Username: `admin`
* Password: `Password1234`

## Creating your own Demo Store

Platform.sh is configured to listen to this repository. Any branch that's pushed here will be created as an inactive environment. To get your own demo store:

* Create a new branch
* Optionally, make any code changes that you need to make
* Push the branch to GitHub
* Activate the environment in Platform.sh

## Updating Magento Version

To update the Magento version in this repository, perform the following actions:

    docker-compose run --rm cli bash
    rm -rf magento
    composer create-project \
        --repository-url=https://repo.magento.com \
        magento/project-enterprise-edition \
        magento
    magento/bin/magento sampledata:deploy
