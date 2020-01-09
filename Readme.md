# MatchBot

MatchBot is a microservice providing real-time donation matching and related APIs.

## Start the app

    docker-compose up -d

## Run scripts in Docker

To use a running app container - not spin up new ones:

    docker-compose exec app composer {name:of:script:from:composer.json}

## Run unit tests in Docker

    docker-compose exec app composer run test
