# eBay Fiddle – Cheap Laptops Ending Soon

Simple PHP web app that uses the **eBay Browse API** to show an information-dense list of cheap laptops (or any category) sorted by **ending soonest**. No login required; uses app-only OAuth.

## Features

- **Keyword** and **category ID** search (default: “laptop”, category 177 – PC Laptops & Netbooks)
- **Max price** filter (default $300)
- **Sort: ending soonest** so auctions and short-dated listings appear first
- Dense table: image, title, price/bid, end date/time, bid count, condition, link to eBay

## Requirements

- PHP 8.0+ with `curl` and `json` extensions
- eBay developer app (Production) with **Browse API** access

## Setup

1. **eBay Developer Account**
   - Go to [eBay Developers Program](https://developer.ebay.com/).
   - Create an application and get **Production** credentials (Client ID and Client Secret).
   - Ensure your app has **OAuth scope** `https://api.ebay.com/oauth/api_scope` (default for Browse API).

2. **Configure credentials**
   - Copy `.env.example` to `.env`.
   - On the [Application Keys](https://developer.ebay.com/my/keys) page, in the **keys table** (not the token popup), copy:
     - **App ID (Client ID)** → put it in `.env` as `EBAY_CLIENT_ID`
     - **Secret** (OAuth Client Secret) → put it in `.env` as `EBAY_CLIENT_SECRET`
   - **Do not** use the value from “Get OAuth Application Token” — that’s a short‑lived token; this app needs the static App ID and Secret so it can request tokens itself.

   Alternatively set the same variables in your environment (e.g. `export EBAY_CLIENT_ID=...`).

3. **Run the app**
   - From the project root:
     ```bash
     php -S localhost:8080 -t public
     ```
   - Open [http://localhost:8080](http://localhost:8080).

## Usage

- On load, the app runs a search with default keyword “laptop”, category **177**, max price **$300**, sorted by **ending soonest**.
- Change keyword, category ID, or max price and click **Search** to refresh.
- Useful category IDs (eBay US): **177** = PC Laptops & Netbooks, **175672** = Laptops & Netbooks.

## Project layout

- `public/index.php` – Single page: form + results table.
- `src/EbayApi.php` – eBay OAuth (client credentials) + Browse API `item_summary/search`.
- `config.php` – Loads `.env` and exposes `$clientId` / `$clientSecret`.

No Composer or external SDK; only PHP and curl.
