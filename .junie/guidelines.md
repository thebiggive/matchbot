# MatchBot Developer Guidelines

## Project Overview
MatchBot is a Slim PHP microservice for real-time donation matching and related APIs. It handles donation processing, match fund allocation, and integrates with payment providers like Stripe.

## Tech Stack
- **Framework**: Slim PHP
- **Database**: MySQL 8 with Doctrine ORM
- **Cache/Queue**: Redis (local), AWS SQS (production)
- **Testing**: PHPUnit
- **Deployment**: Docker, AWS ECS, CircleCI
- **API Documentation**: OpenAPI 3 (SwaggerHub)

## Project Structure
- `/app`: Slim configuration, routing, and dependency injection
- `/src`: Main application code
  - `/Domain`: Data models and business logic
  - `/Application/Actions`: API endpoints
  - `/Application/Commands`: CLI commands
  - `/Client`: External API clients
- `/tests`: Unit tests
- `/integrationTests`: Integration tests
- `/deploy`: Deployment scripts
- `/schema`: Database schema reference

## Development Workflow

### Setup
1. Clone the repository
2. Copy `.env.example` to `.env` and configure as needed
3. Copy `stripe_cli.env.example` to `stripe_cli.env` and configure
4. Run `docker compose up -d` to start the application
5. Run `docker compose exec app composer install` to install dependencies
6. Run `docker compose exec app composer doctrine:delete-and-recreate` to set up the database

### Running Tests
- Unit tests: `docker compose exec app composer run test`
- Integration tests: `docker compose exec app composer run integration-test`
- All checks: `docker compose exec app composer run check`

### Common Tasks
- Clear Redis data: `docker compose exec redis redis-cli FLUSHALL`
- Update database schema:
  1. Modify entity classes
  2. Run `composer doctrine:update-schema`
- List available commands: `docker compose exec app composer matchbot:list-commands`

## Best Practices

### Code Organization
- Follow PSR standards for code style
- Use Doctrine attributes for entity definitions
- Include `#[ORM\HasLifecycleCallbacks]` for models using `TimestampsTrait`
- Keep business logic in Domain classes
- Use dependency injection via the container

### Testing
- Write unit tests for all new functionality
- Use integration tests for database and API interactions
- Ensure tests run in isolation with proper setup/teardown

### Database
- Use Doctrine migrations for schema changes
- Follow the parallel change pattern for schema updates
- Use Redis for high-volume operations to avoid database locks

### Security
- All API endpoints must have proper authentication middleware
- Use rate limiting for public endpoints
- Never expose sensitive data in responses

### Deployment
- Commits to `develop` deploy to staging
- Commits to `main` deploy to production
- Protected branches require passing tests and code reviews
