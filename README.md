# AIVO Optimize Backend

PHP backend API for AIVO Optimize. Handles Stripe payments, webhook processing, and user plan management.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/health` | Health check |
| POST | `/api/create-checkout-session` | Create Stripe Checkout session |
| POST | `/api/verify-session` | Verify payment on return from Stripe |
| POST | `/api/webhook` | Stripe webhook receiver |

---

## Deploy to Railway — Step by Step

### Step 1 — Push to GitHub

```bash
# In this directory:
git init
git add .
git commit -m "Initial AIVO backend"
git branch -M main

# Create a new repo at github.com then:
git remote add origin https://github.com/YOUR_USERNAME/aivo-backend.git
git push -u origin main
```

### Step 2 — Create Railway project

1. Go to [railway.app](https://railway.app) → **New Project**
2. Choose **Deploy from GitHub repo**
3. Select your `aivo-backend` repository
4. Railway detects `nixpacks.toml` and builds automatically

### Step 3 — Add PostgreSQL database

1. In your Railway project → **New Service** → **Database** → **PostgreSQL**
2. Railway automatically injects `DATABASE_URL` into your app — no config needed

### Step 4 — Set environment variables

In Railway → your service → **Variables** tab, add:

```
STRIPE_SECRET_KEY        = sk_live_...        (your Stripe secret key)
STRIPE_WEBHOOK_SECRET    = whsec_...          (from Step 5 below)
STRIPE_PRICE_GROWTH      = price_1TEv26023O8YaRMl19sz7I0I
STRIPE_PRICE_PRO         = price_1TEv5m023O8YaRMlNSKCQGfs
STRIPE_PRICE_AGENCY      = price_1TEv7E023O8YaRMlWipseVgw
APP_ENV                  = production
ALLOWED_ORIGINS          = https://your-html-domain.com
```

> **Do not add STRIPE_SECRET_KEY to any file.** Railway environment variables are encrypted at rest and never appear in code or logs.

### Step 5 — Set up Stripe webhook

1. Go to Stripe Dashboard → **Developers** → **Webhooks** → **Add endpoint**
2. Endpoint URL: `https://YOUR-APP.railway.app/api/webhook`
3. Select these events:
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.payment_failed`
4. Click **Add endpoint**
5. Click **Reveal** on the signing secret → copy `whsec_...`
6. Paste it as `STRIPE_WEBHOOK_SECRET` in Railway variables

### Step 6 — Update the HTML file

In `AIVO_Optimize_Unified_v2.html`, find `STRIPE_CONFIG` and update `apiBase`:

```javascript
const STRIPE_CONFIG = {
  // ...
  apiBase: 'https://YOUR-APP.railway.app',  // ← update this
};
```

Also update `ALLOWED_ORIGINS` in Railway to match the domain where the HTML is hosted.

### Step 7 — Verify deployment

Visit `https://YOUR-APP.railway.app/api/health` — you should see:

```json
{
  "status": "ok",
  "service": "AIVO Optimize API",
  "version": "2.0.0"
}
```

---

## Local Development

```bash
# Install dependencies
composer install

# Copy env file
cp .env.example .env
# Edit .env — add your Stripe test keys

# Run local server
php -S localhost:8000 -t public
```

Test with:
```bash
curl http://localhost:8000/api/health
```

For local Stripe webhook testing, use the [Stripe CLI](https://stripe.com/docs/stripe-cli):
```bash
stripe listen --forward-to localhost:8000/api/webhook
```
The CLI gives you a local `whsec_` signing secret — use that as `STRIPE_WEBHOOK_SECRET` for local dev.

---

## File Structure

```
aivo-backend/
├── public/
│   └── index.php          ← Entry point, CORS, routing
├── src/
│   ├── bootstrap.php      ← Database + Stripe init
│   ├── helpers.php        ← env(), json_response(), etc.
│   ├── Controllers/
│   │   ├── HealthController.php
│   │   ├── CheckoutController.php   ← create-checkout-session, verify-session
│   │   └── WebhookController.php    ← Stripe event handlers
│   └── Models/
│       ├── User.php
│       ├── Subscription.php
│       ├── DiagnosticRun.php
│       └── StripeEvent.php
├── routes/
│   └── api.php            ← Route table
├── database/
│   └── migrate.php        ← Schema, runs on every boot (safe)
├── railway.json           ← Railway deployment config
├── nixpacks.toml          ← Build config (PHP 8.3, extensions)
├── composer.json
└── .env.example
```

---

## Security notes

- Stripe webhook signature is verified on every request to `/api/webhook`
- Idempotency: duplicate webhook events are silently ignored
- Secret key is only read from environment variables, never hardcoded
- CORS `ALLOWED_ORIGINS` should be set to your exact domain in production
- All API keys (OpenAI, Gemini, etc.) stay in the HTML file client-side — rotate them before launch
