# Magento 2.x Demo Store on Platform.sh

This repository contains the code and configuration required to deploy Magento 2.x to a platform.sh environment.

## Updating Magento Version

To update the Magento version in this repository, perform the following actions:

    docker-compose run --rm cli bash
    rm -rf magento
    composer create-project \
        --repository-url=https://repo.magento.com \
        magento/project-enterprise-edition \
        magento
   magento/bin/magento sampledata:deploy
