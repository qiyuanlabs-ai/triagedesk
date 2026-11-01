---
name: refund-policy
description: Flowly's refund rules. Load before proposing or issuing any refund.
---
# Refund policy

- Monthly plans: full refund of the latest payment if the customer asks within 14 days of being charged.
- Annual plans: prorated refund for unused whole months within the first 60 days; after that, no cash refund, offer account credit.
- Duplicate or erroneous charges: always refund the duplicate in full, regardless of date.
- Outages over 4 hours in a billing month: offer 10% of that month's payment as a refund or credit.
- Never refund more than the payment's refundable amount shown by LookupCustomer.
- Always use the payment id from LookupCustomer and state the policy rule in the reason.
- Customers who have already had two refunds this year: propose the refund but say so in the reason.
