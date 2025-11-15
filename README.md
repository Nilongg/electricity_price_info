# Electricity Price Info ⚡⚡

Displays basic black & white charts and raw data for Finland’s electricity price (c/kWh).

Data is fetched live from the Elering API:
https://dashboard.elering.ee/et

## Tech Stack 🤖

- PHP (compatible with older versions)

- No external dependencies required

## How to Run 🏃

The main runnable file is:

- electricity_price_native.php

Serve it using any web server that supports PHP.

### Example setups ⚙️

- Apache (recommended, used during development)

- Nginx + PHP-FPM

- Built-in PHP server (for quick testing):

- php -S localhost:8000 electricity_price_native.php
