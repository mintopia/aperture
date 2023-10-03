# Aperture

## Notes

Run every 5 minutes:

```
docker compose exec librenms php discovery.php -h <switch ip> -m fdb-table,arp-table
```
