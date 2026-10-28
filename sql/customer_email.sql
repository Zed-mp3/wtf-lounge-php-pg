-- Adds an email address to each customer (used for forgot-password codes). Safe to run more than once.
ALTER TABLE customers ADD COLUMN IF NOT EXISTS email VARCHAR(255);

-- No two accounts may share an email (compared case-insensitively). Blank/NULL emails are allowed.
CREATE UNIQUE INDEX IF NOT EXISTS customers_email_unique
  ON customers (lower(email)) WHERE email IS NOT NULL;

-- Fill in existing accounts by hand, for example:
--   UPDATE customers SET email = 'jane@gmail.com' WHERE customer_id = 1;
--   UPDATE customers SET email = 'juan@gmail.com' WHERE phone = '0917-123-4567';

-- Optional but recommended on Supabase: stop the public REST API from exposing this table.
-- Your PHP app connects as the postgres role, which is not affected.
--   ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
