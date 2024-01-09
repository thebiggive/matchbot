### SQL queries for running manually with a read-only database connection:

## Gift Aid Report:

Fill in the salesforce ID for the charity of interest to run.

```mysql
SELECT Donation.id as DonationID,
       Charity.name as "Charity Name",
       Charity.regulatorNumber as "Charity Reg number",
       Charity.hmrcReferenceNumber as "Charity HMRC Ref",

       Donation.tbgGiftAidRequestCorrelationId as "Correlation ID",
       Donation.amount as "Donation Value in GBP",
       Donation.tbgGiftAidRequestConfirmedCompleteAt as "Gift Aid Request Confirmed at"
FROM Donation
         JOIN Campaign on Donation.campaign_id = Campaign.id
         JOIN Charity on Campaign.charity_id = Charity.id
WHERE Charity.salesforceId = "_______" AND
    Donation.tbgGiftAidRequestQueuedAt is not null;
```

