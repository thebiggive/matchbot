# OpenAPI Documentation in MatchBot

## Overview

This document describes the OpenAPI documentation implementation in the MatchBot project.

## Implementation Details

The MatchBot project uses [OpenAPI/Swagger](https://swagger.io/specification/) to document its API endpoints and data models. The documentation is generated using the [zircote/swagger-php](https://github.com/zircote/swagger-php) library.

### Annotated Classes

The following classes have been annotated with OpenAPI annotations:

- `\MatchBot\Application\HttpModels\Campaign`
- `\MatchBot\Application\HttpModels\MetaCampaign`
- `\MatchBot\Application\HttpModels\Charity`

Supporting classes with annotations:
- `\MatchBot\Domain\BannerLayout`
- `\MatchBot\Domain\Colour`
- `\MatchBot\Domain\FocalAreaBox`

### Generating Documentation

The OpenAPI documentation can be generated using the following Composer commands:

```bash
# Generate documentation from PHP annotations only
composer run docs

# Merge manually written api.yaml with annotation-based documentation
composer run docs:merge

# Generate and merge documentation in one step
composer run docs:all
```

The `docs` command generates OpenAPI documentation from PHP annotations only and saves it to `docs/openapi.yaml`.

The `docs:merge` command merges the manually written API documentation in `api.yaml` with the documentation generated from PHP annotations.

The `docs:all` command runs both steps in sequence, ensuring a complete documentation output that includes both sources.

### CI/CD Integration

The CircleCI configuration has been updated to:
1. Run the documentation generation command during the build process
2. Store the generated documentation as build artifacts

### Viewing the Documentation

The generated OpenAPI documentation can be viewed using any OpenAPI/Swagger UI tool. You can:

1. Use the [Swagger Editor](https://editor.swagger.io/) and paste the contents of the `docs/openapi.yaml` file
2. Use the [Swagger UI](https://swagger.io/tools/swagger-ui/) to host the documentation
3. Access the documentation through CircleCI artifacts after a successful build

## Future Improvements

Potential future improvements to the OpenAPI documentation:
- Add annotations for API endpoints
- Integrate Swagger UI directly into the project
- Add more detailed examples and descriptions
