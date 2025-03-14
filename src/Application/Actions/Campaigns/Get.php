<?php

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Get extends Action{

    protected function action(Request $request, Response $response, array $args): Response
    {
        $campaignJson = <<<JSON
            {
              "video" : {
                "provider" : "youtube",
                "key" : "12345"
              },
              "usesSharedFunds" : false,
              "updates" : [ ],
              "title" : "2025 Year-End Matched-Funding Campaign",
              "thankYouMessage" : "Thank you for your support...",
              "target" : 10000.00,
              "surplusDonationInfo" : null,
              "summary" : "We are raising funds...",
              "status" : "Active",
              "startDate" : "2024-10-09T08:00:00.000Z",
              "solution" : "The Foundation is addressing...",
              "regularGivingCollectionEnd" : null,
              "ready" : true,
              "quotes" : [ {
                "quote" : "Dear Sam...",
                "person" : "Alex"
              } ],
              "problem" : "The Foundation is addressing...",
              "parentUsesSharedFunds" : false,
              "parentTarget" : null,
              "parentRef" : null,
              "parentDonationCount" : null,
              "parentAmountRaised" : null,
              "matchFundsTotal" : 5000.00,
              "matchFundsRemaining" : 50,
              "logoUri" : null,
              "isRegularGiving" : false,
              "isMatched" : true,
              "impactSummary" : "Across our pillars, ",
              "impactReporting" : "Impact will be measured according to the pillars..",
              "id" : "a05xa000000",
              "hidden" : false,
              "endDate" : "2025-12-31T23:59:00.000Z",
              "donationCount" : 100,
              "currencyCode" : "GBP",
              "countries" : [ "United Kingdom"],
              "charity" : {
                "website" : "https://www.example.org/",
                "twitter" : null,
                "stripeAccountId" : "acct_00000000",
                "regulatorRegion" : "England and Wales",
                "regulatorNumber" : "1112345",
                "postalAddress" : {
                  "postalCode" : "W11 111",
                  "line2" : null,
                  "line1" : "12 Some Square",
                  "country" : "United Kingdom",
                  "city" : "London"
                },
                "phoneNumber" : null,
                "optInStatement" : null,
                "name" : "Some charity name",
                "logoUri" : "",
                "linkedin" : "https://www.linkedin.com/company/xyz/",
                "instagram" : "https://www.linkedin.com/company/xyz",
                "id" : "000000000000000000",
                "hmrcReferenceNumber" : null,
                "giftAidOnboardingStatus" : "Invited to Onboard",
                "facebook" : "https://www.facebook.com/xyz",
                "emailAddress" : "email@example.com"
              },
              "championRef" : null,
              "championOptInStatement" : null,
              "championName" : null,
              "categories" : [ "Health/Wellbeing", "Medical Research", "Mental Health" ],
              "campaignCount" : null,
              "budgetDetails" : [ {
                "description" : "Budget item 1",
                "amount" : 1000.00
              }, {
                "description" : "Budget item 2",
                "amount" : 1000.00
              }, {
                "description" : "Budget item 3",
                "amount" : 7500.00
              }, {
                "description" : "Overhead",
                "amount" : 500.00
              } ],
              "beneficiaries" : [ "General Public/Humankind", "Women & Girls" ],
              "bannerUri" : "",
              "amountRaised" : 50000.00,
              "alternativeFundUse" : "We have initiatives that require larger amounts of funding...",
              "aims" : [],
              "additionalImageUris" : [ {
                "uri" : "",
                "order" : 100
              } ]
              }
            JSON;
        return $this->respondWithData($response, json_decode($campaignJson));
    }
}