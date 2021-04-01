# MatchBot

MatchBot is a [Slim](https://www.slimframework.com/) PHP microservice providing real-time donation matching and
related APIs.

* [Run the app](#Run-the-app)
* [Run unit tests](#Run-unit-tests)
* [Service dependencies](#Service-dependencies)
* [Scripts and Docker](#Scripts-and-Docker)
* [Code structure](#Code-structure)
* [Deployment](#Deployment)
* [APIs](#APIs)

## Run the app

You should usually use Docker to run the app locally in an easy way, with the least possible
configuration and the most consistency with other runtime environments - both those used
when the app is deployed 'for real' and other developers' machines.

### Prerequisites

In advance of the first app run:

* [get Docker](https://www.docker.com/get-started)
* copy `.env.example` to `.env` and change any values you need to. e.g. if you
  are working with your own Salesforce sandbox you would want to change most of the `SALESFORCE_*`
  variables.

### Start the app

To start the app and its dependencies (`db` and `redis`) locally:

    docker-compose up -d

### First run

To get PHP dependencies and an initial data in structure in place, you'll need to run these once:

    docker-compose exec app composer install
    docker-compose exec app composer doctrine:delete-and-recreate

If dependencies change you may occasionally need to re-run the `composer install`.

### Data clearing on subsequent runs

If you have already run local tests with fund balances being updated in Redis,
you will need to flush Redis data to start afresh. You can remove and re-create the
Docker container, or just flush the data store:

    docker-compose exec redis redis-cli FLUSHALL

## Run unit tests

Once you have the app running, you can test with: 

    docker-compose exec app composer run test

or

    docker-compose exec app composer run test-with-coverage

to save coverage data to `./coverage.xml`.

Linting is run with

    docker-compose exec app composer run lint:check

To understand how these commands are run in CI, see [the CircleCI config file](./.circleci/config.yml).

## Service dependencies

### MySQL

MySQL 8 is the permanent, persisted 'source of truth' for most data.

It's pretty fast when a large instance is used, but in very large load tests it still hit very occasional record
locking errors. Retries could make this workable up to pretty high volumes, but using Redis as the matching adapter
offers a way to avoid locks in all cases and retries in a much higher proportion of cases.

### Redis

Redis is used for Doctrine caches but also for real-time matching allocations, to enable very high volume use without
worrying about database locks. When Redis is the matching adapter (currently always), match data is normally sent to
MySQL after the fact, but Redis handles fund balances so that MySQL is never involved in race conditions.

In [`OptimisticRedisAdapter`](./src/Application/Matching/OptimisticRedisAdapter.php) we use atomic, multiple Redis
operations which can both init a value (if empty) and increment/decrement it (unconditionally), eliminating the need
for locks. In the rare edge case where two processes do this near-simultaneously based on slightly out of date database
values, and a fund's balance drops below zero, the thread which last changed the value immediately 'knows' this happened
from the return value and will reverse and re-try the operation based on the new state of play, just as quickly as the
first attempt.

## Scripts and Docker

You can see how custom scripts are defined in [`composer.json`](./composer.json). They all have Composer script names
starting `matchbot:`. You should be careful about running any script manually, but especially those that do *not* have
this prefix. e.g. there is a `doctrine:` script to completely empty and reset your database.

### Discovering more about MatchBot scripts

The headline for each script's purpose is defined in its description in the PHP class. There is a Composer script
`matchbot:list-commands` which calls `list` to read these. So with an already-running Docker `app` container, you can
run

    docker-compose exec app composer matchbot:list-commands

for an overview of how all [`Commands`](./src/Application/Commands) in the app describe themselves.

### Running scripts locally

To run a script in an already-running Docker `app` container, use:

    docker-compose exec app composer {name:of:script:from:composer.json}

### How tasks run on staging & production

[ECS](https://aws.amazon.com/ecs/) task invocations are configured to run the tasks we expect to happen regularly
on a schedule. Tasks get their own ECS cluster to run on, independent of the web cluster, usually with just one instance
per environment. They are triggered by CloudWatch Events Rules which fire at regular intervals.

## Code structure

MatchBot's code was originally organised loosely based on the [Slim Skeleton](https://github.com/slimphp/Slim-Skeleton),
and elements like the error & shutdown handlers and much of the project structure follow its conventions.

Generally this structure follows normal conventions for a modern PHP app:

* Dependencies are defined (only) in `composer.json`, including PHP version and extensions
* Source code lives in [`src`](./src)
* PHPUnit tests live in [`tests`](./tests), at a path matching that of the class they cover in `src`
* Slim configuration logic and routing live in [`app`](./app)

### Configuration in `app`

* [`dependencies.php`](./app/dependencies.php) and [`repositories.php`](./app/repositories.php): these set up dependency
  injection (DI) for the whole app. This determines how every class gets the stuff it needs to run. DI is super
  powerful because of its flexibility (a class can say _I want a logger_ and not worry about which one), and typically
  avoids objects being created that aren't actually needed, or being created more times than needed. Both of these files
  work the same way - they are only separate for cleaner organisation.

  We use Slim's [PSR-11](https://www.php-fig.org/psr/psr-11/) compliant Container with [PHP-DI](http://php-di.org/).
  There's an [overview here](https://www.slimframework.com/docs/v4/concepts/di.html) of what this means in the context
  of Slim v4.

  With PHP-DI, by tuning dependencies to be more class-based we could potentially eliminate some of our explicit
  depenendency definitions in the future by taking better advantage of [autowiring](http://php-di.org/doc/autowiring.html).
* [`routes.php`](./app/routes.php): this small file defines every route exposed on the web, and every authentication
  rule that applies to them. The latter is controlled by [PSR-15](https://www.php-fig.org/psr/psr-15/) middleware and
  is very important to keep in the right place!
  
  Slim uses methods like `get(...)` and `put(...)` to hook up specific HTTP methods to classes that should be invoked.
  Our `Action`s' boilerplate is set up so that when the class is invoked, its `action(...)` method does the heavy
  lifting to serve the request.

  `add(...)` is responsible for adding middleware. It can apply to a single route or a whole group of them. Again, this
  is how we make routes authenticated. **Modify with caution!**
* [`settings.php`](./app/settings.php): you won't normally need to do much with this directly because it mostly just
  re-structures environment variables found in `.env` (locally) or env vars loaded from a secrets file (on ECS), into
  formats expected by classes we feed config arrays.

### Important code

The most important areas to explore in `src` are:

* [`Domain`](./src/Domain): defines the whole app's data structure. This is essential to both the code and how the
  database schema definition is generated. Changes here must be accompanied by Doctrine-generated migrations
  so the database stays in sync. [Doctrine annotations](https://www.doctrine-project.org/projects/doctrine-orm/en/2.7/reference/annotations-reference.html)
  are used to define important aspects of the data model. Two special things to notice:
  1. Several models rely on the `@ORM\HasLifecycleCallbacks` annotation. In many cases this is because they
     `use TimestampsTrait`. This is a nice time saver but models _must_ include the lifecycle annotation, or their
     timestamps won't work.
  2. [`Fund`](./src/Domain/Fund.php) and its subclasses use [Single Table Inheritance](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/inheritance-mapping.html#single-table-inheritance).
     The point is to build a semantic distinction between fund types (`ChampionFund` vs. `Pledge`) without adding
     avoidable complexity to the database schema, as both objects are extremely similar in their data structure
     and behaviour.
* [`Client`](./src/Client): custom API clients for communicating with our Salesforce Site.com REST APIs.
* [`Application\Actions`](./src/Application/Actions): all classes exposing MatchBot APIs to the world. Anything invoked
  directly by a Route should be here.
* [`Application\Commands`](./src/Application/Commands): all classes extending `Command` (we use the [Symfony Console](https://symfony.com/doc/current/console.html)
  component). Every custom script we invoke and anything extending `Command` should be here.

There are other very important parts of the app, e.g. `Application\Matching` (tracking match fund allocations)
and `Application\Auth` (security middleware), but generally you should not need to change them. They hopefully also
have enough inline documentation to be reasonably easy to understand, when you encounter other code that invokes them.

## Deployment

Deploys are rolled out by [CirlceCI](https://circleci.com/), as [configured here](./.circleci/config.yml), to an
[ECS](https://aws.amazon.com/ecs/) cluster, where instances run the app live inside Docker containers.

As you can see in the configuration file,

* `develop` commits trigger deploys to staging and regression environments; and
* `main` commits trigger deploys to production

These branches are protected on GitHub and you should have a good reason for skipping any checks before merging to them!

### ECS runtime containers

ECS builds have two additional steps compared to a local run:

* during build, the [`Dockerfile`](./Dockerfile) adds the AWS CLI for S3 secrets access, pulls in the app files, tweaks
  temporary directory permissions and runs `composer install`. These things don't happen automatically with the [base
  PHP image](https://github.com/thebiggive/docker-php) as they don't usually make sense for local runs;
* during startup, the entrypoint scripts load in runtime secrets securely from S3 and run some Doctrine tasks to ensure
  database metadata is valid and the database itself is in sync with the new app code. This is handled in the two
  `.sh` scripts in [`deploy`](./deploy) - one for web instances and one for tasks.

### Phased deploys

Other AWS infrastructure includes a load balancer, and ECS rolls out new app versions gradually to try and keep a
working version live even if a broken release is ever deployed. Because of this, new code may not reach all users until
about 30 minutes after CircleCI reports that a deploy is done. You can monitor this in the AWS Console.

When things are working correctly, any environment with at least two tasks in its ECS Service should get new app
versions with no downtime. If you make schema changes, be careful to use a [parallel change (expand / contract)](https://www.martinfowler.com/bliki/ParallelChange.html)]
pattern to ensure this remains true.

## APIs

The API contracts which the app fulfils are currently kept on SwaggerHub and
split across two manually-maintained docs:

* payment status update hook: [DonationWebhookSwagger2](https://app.swaggerhub.com/apis/Noel/DonationWebhookSwagger2) – using Swagger 2 for historical reasons
* everything else: [TBG-Donations](https://app.swaggerhub.com/apis/Noel/TBG-Donations) – using OpenAPI 3

The app also implements *clients* for some endpoints defined in these and some other API
contracts, including:

* [TBG-Campaigns](https://app.swaggerhub.com/apis/Noel/TBG-Campaigns)
* [TBG-Funds](https://app.swaggerhub.com/apis/Noel/TBG-Funds)
