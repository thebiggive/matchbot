# Mailing List Signup

## Overview

The Mailing List Signup feature allows users to sign up for either the donor or charity mailing list. This document describes the implementation and usage of this feature.

## API Endpoint

```
POST /v1/mailing-list-signup
```

### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| mailinglist | string | Yes | Either "donor" or "charity" |
| firstName | string | Yes | First name of the person signing up |
| lastName | string | Yes | Last name of the person signing up |
| emailAddress | string | Yes | Email address of the person signing up |
| jobTitle | string | Yes (for charity) | Job title (required for charity mailing list) |
| organisationName | string | No | Organisation name |

### Response

#### Success Response

```json
{
  "success": true,
  "message": "Successfully signed up to mailing list"
}
```

#### Error Response

```json
{
  "success": false,
  "message": "Error message"
}
```

## Implementation Details

The implementation consists of two main components:

1. **MailingList Client**: A client class that handles communication with the Salesforce API.
2. **MailingListSignup Action**: An action class that handles the HTTP request, validates the input, and calls the client.

### MailingList Client

The `MailingList` client extends the `Common` client class and uses the `HashTrait` for authentication. It sends a POST request to the Salesforce API endpoint `/mailing-list-signup` with the provided data.

### MailingListSignup Action

The `MailingListSignup` action class extends the `Action` class and handles the HTTP request. It validates the input parameters and calls the `MailingList` client to send the request to Salesforce.

## Salesforce API

**Note**: The Salesforce API endpoint `/mailing-list-signup` needs to be created in Salesforce. Currently, the endpoint returns a 403 Forbidden error.

## Security

The endpoint is protected by the `CaptchaMiddleware` in production to prevent spam and abuse. For testing purposes, this middleware can be bypassed.

## Testing

To test the endpoint, you can use the following curl command:

```bash
curl -X POST http://localhost:30030/v1/mailing-list-signup \
  -d "mailinglist=donor" \
  -d "firstName=Test" \
  -d "lastName=User" \
  -d "emailAddress=test@example.com" \
  -d "organisationName=Test Organization"
```

For charity mailing list signup, include the required `jobTitle` parameter:

```bash
curl -X POST http://localhost:30030/v1/mailing-list-signup \
  -d "mailinglist=charity" \
  -d "firstName=Test" \
  -d "lastName=User" \
  -d "emailAddress=test@example.com" \
  -d "jobTitle=CEO" \
  -d "organisationName=Test Charity"
```
