# Cloudflare R2 — configuração privada

O projeto usa:

- Account ID: `a26bcc0f570221207e6e66981adae363`
- Bucket: `superamplitude`
- Endpoint: `https://a26bcc0f570221207e6e66981adae363.r2.cloudflarestorage.com`
- Catálogo: `https://catalog.cloudflarestorage.com/a26bcc0f570221207e6e66981adae363/superamplitude`
- CDN pública: `https://img.farmacia.superamplitude.com`

As credenciais de escrita **não são armazenadas no repositório**. Elas devem existir somente em `/home/superamplitude/.farmacia/.env` com as chaves:

```text
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
```

Depois de configuradas, execute:

```bash
php scripts/r2_check.php
```

Se a assinatura S3 e as permissões estiverem corretas, o teste grava um pequeno JSON em `health/` e retorna `R2_CHECK_OK` com a URL pública no domínio `img.farmacia.superamplitude.com`.
