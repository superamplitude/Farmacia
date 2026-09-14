# Cloudflare R2 — configuração privada

O projeto usa:

- Account ID: `a26bcc0f570221207e6e66981adae363`
- Bucket: `superamplitude`
- Endpoint: `https://a26bcc0f570221207e6e66981adae363.r2.cloudflarestorage.com`
- Catálogo: `https://catalog.cloudflarestorage.com/a26bcc0f570221207e6e66981adae363/superamplitude`
- CDN pública: `https://img.farmacia.superamplitude.com`

As credenciais de escrita **não são armazenadas no repositório**. Elas devem existir somente em `/home/farmacia/.farmacia/.env`:

```text
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
```

O deploy executa `php scripts/r2_check.php` automaticamente quando as duas variáveis estão preenchidas. A integração de escrita só é considerada validada quando o log retorna `R2_WRITE=ok`/`R2_CHECK_OK`.

O teste grava um pequeno objeto JSON sob `health/` e gera a URL pública por `https://img.farmacia.superamplitude.com/...`.
