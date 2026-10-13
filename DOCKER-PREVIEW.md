# NEXXI Kids — Docker preview

The preview runs at <http://localhost:8088>. Docker publishes it on `127.0.0.1` only. The theme and plugin are mounted from this workspace, so code edits appear after refreshing the browser.

## Start or resume

```powershell
docker compose -f docker-compose.preview.yml up -d db wordpress
```

The existing preview volume already contains WordPress, WooCommerce and the demo catalog. Use these initialization steps only with a fresh volume:

```powershell
docker compose -f docker-compose.preview.yml run --rm cli wp core install --url=http://localhost:8088 --title="NEXXI Kids Preview" --admin_user=previewadmin --admin_password="Preview123!Local" --admin_email=preview@example.test --skip-email
docker compose -f docker-compose.preview.yml run --rm cli wp plugin install woocommerce --activate
docker compose -f docker-compose.preview.yml run --rm cli wp theme activate nexxi-kids
docker compose -f docker-compose.preview.yml run --rm cli wp plugin activate nexxi-kids-commerce
docker compose -f docker-compose.preview.yml run --rm cli wp language core install vi
docker compose -f docker-compose.preview.yml run --rm cli wp site switch-language vi
docker compose -f docker-compose.preview.yml run --rm cli wp language plugin install woocommerce vi
docker compose -f docker-compose.preview.yml run --rm cli wp eval-file /preview-import/seed-preview.php
```

The seed script can be run again to refresh the sample catalog and store settings. The local administrator login is `previewadmin` / `Preview123!Local` at <http://localhost:8088/wp-admin/>. These credentials are for the local demo only.

## Stop

```powershell
docker compose -f docker-compose.preview.yml down
```

This keeps the database and WordPress content volumes for the next start.
