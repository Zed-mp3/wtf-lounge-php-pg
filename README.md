# WTF Lounge — updated build

## What changed

**Structure.** Files now live where the PHP already expected them
(`config/db.php`, `includes/*.php`, `css/style.css`) instead of flat —
that's why the originals wouldn't run as uploaded.

**Login gate.** `index.php` is now just a redirect: logged-in visitors
go to `menu.php` (the old menu/home page), everyone else goes to
`login.php`. Every page that touches the cart, checkout, order history,
or the menu itself requires login.

**Search.** The menu page has a search box (`?q=...`) that filters by
product name, combinable with the category chips.

**Mobile / side nav.** Below 768px the top nav collapses into a
hamburger button that opens an off-canvas side nav (menu, orders, cart,
admin link if applicable, account). `css/style.css` is a functional
placeholder stylesheet — swap in your real one whenever it's ready; the
class names already match what the templates use.

**Admin.** New `/admin` section (requires `is_admin = 1` on the
customer's account):
- `admin/index.php` — dashboard with order/revenue stats.
- `admin/orders.php` — search/filter orders, update status inline
  (Pending → Preparing → ... → Completed/Cancelled).
- `admin/users.php` — search customers, promote/revoke admin, set a
  password for an account (needed for legacy no-password rows, see
  below).

## Setup (PostgreSQL)

1. Create the database and load the schema (PostgreSQL 12+):
   ```bash
   createdb db_shop
   psql -d db_shop -f sql/db_shop.sql
   ```
2. Point the app at it — either edit the defaults in `config/db.php` or set
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` environment variables.
   PHP needs the `pdo_pgsql` extension (`php-pgsql` on Debian/Ubuntu).
3. Load the site, click **Register**, create your account.
4. Promote yourself to admin (one-time):
   ```sql
   UPDATE customers SET is_admin = TRUE WHERE phone = 'YOUR-PHONE-HERE';
   ```
5. Log out and back in — you'll see the **Admin** link in the nav.
6. Drop in your real `css/style.css` whenever you have it.

## MySQL → PostgreSQL conversion notes

The original MySQL schema is kept in `sql/mysql/db_shop.sql` for reference.

- **Columns renamed to snake_case** (`productID` → `product_id`, `orderID` →
  `order_id`, `customerID` → `customer_id`, `categoryID` → `category_id`,
  `orderdetailID` → `order_detail_id`). PostgreSQL lower-cases unquoted names, so
  the old camelCase keys would not have survived; all PHP was updated to match.
- **Types:** `AUTO_INCREMENT` → identity columns, `TINYINT(1)` flags → `BOOLEAN`,
  `DOUBLE` money → `NUMERIC(10,2)`, `DATETIME` → `TIMESTAMP`, `ENUM` → `CHECK`.
  `NUMERIC` values come back from PDO as strings; the app already formats/casts them.
- **Triggers** rewritten in PL/pgSQL. The stock check raises SQLSTATE `45000`
  (as the MySQL `SIGNAL` did), so `checkout.php` still shows the friendly message.
- **PHP changes:** `lastInsertId()` → `INSERT ... RETURNING`; `LIKE` → `ILIKE`
  (MySQL's was case-insensitive); `is_active = 1` → `is_active`; report queries
  group by the full set of selected columns (PostgreSQL is stricter than MySQL).
- Existing MySQL data can be moved with `pgloader` (rename the ID columns after
  loading, or load into the new schema table by table).
- `admin/index.php.bak` is an old backup and was not converted.

## Security changes made along the way

- **CSRF protection** on every state-changing form (cart, checkout,
  login, register, reorder, admin actions) via a per-session token.
- **Session hardening**: `httponly`/`samesite` cookies, and
  `session_regenerate_id()` on login/register to prevent session
  fixation.
- **Removed the passwordless legacy login path.** The original code let
  anyone log in to any account that had no password set yet just by
  knowing its phone number. Those accounts now can't log in until an
  admin sets a password for them from Admin → Users.
- **Basic login rate limiting** (5 attempts / 5 minutes per phone
  number).
- **Order/receipt ownership checks.** `confirmation.php` and the
  `orders.php` reorder action used to trust an order code or phone
  number from the URL/POST with no ownership check — anyone who saw or
  guessed an order code could view someone else's receipt or dump their
  items into a cart. Both now verify the order belongs to the logged-in
  account (admins can still view any order).
- **Order history is scoped to the logged-in account** instead of a
  public "look up any phone number" form.
- **Open-redirect fix**: the `redirect` field on cart actions is now
  validated to only point at a local `*.php` page.
- **No more raw exception text sent to the browser** (registration
  errors, DB connection failures) — set `APP_DEBUG = true` in
  `config/db.php` only while developing locally.
- **Stronger password rule** (8+ characters) on registration; admins
  setting a password for someone else are held to the same minimum.
- **Self-lockout guard**: an admin can't accidentally revoke their own
  admin access from the Users page.

## Still worth doing before going live

- Serve over HTTPS (several of the hardening steps above, like secure
  cookies, only fully kick in over HTTPS).
- Add a real "forgot password" flow.
- Consider moving `config/db.php` credentials to environment variables.
- Add pagination to `admin/orders.php` / `admin/users.php` once you
  have real volume.
