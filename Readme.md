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
when in production and similar environments and on and other developers' machines.

### Prerequisites

In advance of the first app run:

* [get Docker](https://www.docker.com/get-started)
* If you use an Apple Silicon system, disable _"Use Rosetta for x86/amd64 emulation on Apple Silicon"_ in Docker Desktop
  preferences.
* copy `.env.example` to `.env` and change any values you need to. e.g. if you
  are working with your own Salesforce sandbox you would want to change most of the `SALESFORCE_*`
  variables.

### Set up a connection to the Stripe test environment

In dev environments, we use [Stripe CLI](https://stripe.com/docs/stripe-cli?locale=en-GB) in its own docker container
to pull events from Stripe and forward them to the local HTTP server.

Visit https://dashboard.stripe.com/test/webhooks and select "Add local listener".

Instead of running the suggested commands natively, use:
* `docker compose run --rm stripe-cli login`
Use the "CLI webhook secret" that
Stripe will give you to replace the value of `STRIPE_WEBHOOK_SIGNING_SECRET` in `.env`. Make sure you also have a good
value for `STRIPE_SECRET_KEY` set in `.env`. You will also find this in the Stripe dashboard, and in a dev environment 
it should start with `rk_test_` or `sk_test_`.

Copy `stripe_cli.env.example` to `stripe_cli.env`.  

Inside `stripe_cli.env` set STRIPE_API_KEY to the same value as `STRIPE_SECRET_KEY` in `.env`, and replace "some-developer"
with your name in `STRIPE_DEVICE_NAME = some-developer-dev`.

As there is only one stripe test environment, your copy of matchbot will receive events relating to tests done by others
in dev, staging and regression test environments as well as yourself, but other than causing errors to be logged, these 
should be harmless if you are not touching the same records in stripe. Receiving webhooks enables things like setting
donations to a completed status which means we can display the "thanks" page, and passing on the message to `mailer` when
a donation funds user transfers cash into their account.

### Start the app

To start the app and its dependencies (`db` and `redis`) locally:

```shell
    docker compose up -d
```


### First run

To get PHP dependencies and an initial data in structure in place, you'll need to run these once:

```shell
    docker compose exec app composer install
    docker compose exec app composer doctrine:delete-and-recreate
```

If dependencies change you may occasionally need to re-run the `composer install`.

### Data clearing on subsequent runs

If you have already run local tests with fund balances being updated in Redis,
you will need to flush Redis data to start afresh. You can remove and re-create the
Docker container, or just flush the data store:

```shell
    docker compose exec redis redis-cli FLUSHALL
```

## Run Checks

To run multiple checks as circleCI does when you push any commit, use:

```shell
docker compose exec app composer run check
```

Currently, this includes automated tests, static analysis and code linting.

For individual checks see following sections.

## Run unit tests

Once you have the app running, you can test with: 

```shell
    docker compose exec app composer run test
    docker compose exec app composer run integration-test
```

When run with a coverage driver (e.g. Xdebug enabled by using the usual local dev image),
this will save coverage data to `./coverage.xml` and `./coverage-integration.xml`.

Linting is run with

```shell
    docker compose exec app composer run lint:check
```

To understand how these commands are run in CI, see [the CircleCI config file](./.circleci/config.yml).

## Service dependencies

### MySQL

MySQL 8 is the permanent, persisted 'source of truth' for most data.

It's pretty fast when a large instance is used, but in very large load tests it still hit very occasional record
locking errors. Retries could make this workable up to pretty high volumes, but using Redis as the matching adapter
offers a way to avoid locks in all cases and retries in a much higher proportion of cases.

### Doctrine ORM

Our MySQL DB schema is generated from annotations on Doctrine Entities (Campaign, Donation etc). Copies of create table
statements are available for reference in the [schema directory](./schema).
To update the schema, edit the properties on the entity, then run:

```shell
  composer doctrine:update-schema
```

This will generate and run new migration file for changing the DB schema from what you had before to the one required
for your updated entity classes, execute it, and then output the reference schema files. Alternatively you may run
commands one at a time:

```shell
  composer doctrine:cache:clear
  vendor/bin/doctrine-migrations diff --allow-empty-diff
  vendor/bin/doctrine-migrations migrate
  ./matchbot matchbot:write-schema-files
```

However, in dev environments there are two databases, one for manual tests and one for automated tests, so the migrations
step will need to be run for both. Using `composer doctrine:update-schema` does this for you.

### Redis

Redis is used for Doctrine caches but also for real-time matching allocations, to enable very high volume use without
worrying about database locks. When Redis is the matching adapter (currently always), match data is normally sent to
MySQL after the fact, but Redis handles fund balances so that MySQL is never involved in race conditions.

In [`OptimisticRedisAdapter`](./src/Application/Matching/Adapter.php) we use atomic, multiple Redis
operations which can both init a value (if empty) and increment/decrement it (unconditionally), eliminating the need
for locks. In the rare edge case where two processes do this near-simultaneously based on slightly out of date database
values, and a fund's balance drops below zero, the thread which last changed the value immediately 'knows' this happened
from the return value and will reverse and re-try the operation based on the new state of play, just as quickly as the
first attempt.

### SQS

In deployed environments, AWS Simple Queue Service is used to pick up
messages for delayed processing – MatchBot needs to handle messages for Stripe
payout paid webhooks, which take some time to process, and for completed/failed Gift Aid
claim attempts from ClaimBot.

Additionally, it *publishes* messages to ClaimBot's separate queue for newly-claimable donations and its own when a
new payout hook is just received and requires prompt acknowledgement.

Locally, Redis is used for queues instead. The `MESSENGER_TRANSPORT_DSN` and
`CLAIMBOT_MESSENGER_TRANSPORT_DSN` env vars determine the queue configuration
for Symfony Messenger.

## Scripts and Docker

You can see how custom scripts are defined in [`composer.json`](./composer.json). They all have Composer script names
starting `matchbot:`. You should be careful about running any script manually, but especially those that do *not* have
this prefix. e.g. there is a `doctrine:` script to completely empty and reset your database.

### Discovering more about MatchBot scripts

The headline for each script's purpose is defined in its description in the PHP class. There is a Composer script
`matchbot:list-commands` which calls `list` to read these. So with an already-running Docker `app` container, you can
run

```shell
    docker compose exec app composer matchbot:list-commands
```

for an overview of how all [`Commands`](./src/Application/Commands) in the app describe themselves.

### Running scripts locally

To run a script in an already-running Docker `app` container, use:

```shell
    docker compose exec app composer {name:of:script:from:composer.json}
```

#### Seeding with fictional data

You can test donating using a campaign in your local frontend database that doesn't have to exist in any Salesforce org.

To add the fictional campaign without clearing any existing data, run

    docker compose exec app ./matchbot matchbot:create-fictional-data 

You should then be able to donate by visiting http://localhost:4200/campaign/000000000000000000

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
  so the database stays in sync. 
  [Doctrine attributes](https://www.doctrine-project.org/projects/doctrine-orm/en/2.17/reference/attributes-reference.html)
  are used to define important aspects of the data model.  

  * Several models rely on the `#[ORM\HasLifecycleCallbacks]` attribute. In many cases this is because they
   `use TimestampsTrait`. This is a nice time saver but models _must_ include the lifecycle attribute, or their
   timestamps won't work.

* [`Client`](./src/Client): custom API clients for communicating with our Salesforce Site.com REST APIs.
* [`Application\Actions`](./src/Application/Actions): all classes exposing MatchBot APIs to the world. Anything invoked
  directly by a Route should be here.
* [`Application\Commands`](./src/Application/Commands): all classes extending `Command` (we use the 
  [Symfony Console](https://symfony.com/doc/current/console.html) component). Every custom script we invoke and anything
  extending `Command` should be here.

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
versions with no downtime. If you make schema changes, be careful to use a 
[parallel change (expand / contract)](https://www.martinfowler.com/bliki/ParallelChange.html)
pattern to ensure this remains true.

## APIs

The OpenAPI contract which the app fulfils (along with Salesforce) is mostly kept in this repo – via a mix of hard-coded schemas in [api.yaml](./api.yaml) and Openapi library attributes. We'll probably incrementally move to the latter so that definitions live near their code.

Operations covered in APIs we document largely map to the donor-facing responsibilities of MatchBot. They cover the most important calls relating to Donations and Campaigns.

The app also implements a *client* for some endpoints including for Funds, with a hard-coded API doc online at [TBG-Funds](https://app.swaggerhub.com/apis/Noel/TBG-Funds). (Technically this is missing the most important endpoint, `/campaign/{id}/funds`, which returns a list of the Scheme defined here. SwaggerHub is currently unusable on a free plan and this didn't seem worth migrating away right now.)

## Rate limits

There are two types of rate limiting used in the app to help protect against abuse:

| Library              | Where in stack                                      | Keyed on                | Used for                                |
|----------------------|-----------------------------------------------------|-------------------------|-----------------------------------------|
| los/los-rate-limit   | Middlewares in [app/routes.php](./app/routes.php)   | LB-forwarded IP address | Several donation and account operations |
| symfony/rate-limiter | [DonationService](./src/Domain/DonationService.php) | Stripe Customer ID      | Donation & Payment Intent creation      |

## Documentation

Further docs available at [docs](./docs).
