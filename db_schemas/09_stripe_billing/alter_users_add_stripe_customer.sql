-- Store Stripe customer ID on the user row so that subscription renewal
-- webhooks (invoice.payment_succeeded) can look up the user without
-- requiring an additional mapping table.
ALTER TABLE users
    ADD COLUMN stripe_customer_id VARCHAR(64) NULL UNIQUE
    AFTER role;
