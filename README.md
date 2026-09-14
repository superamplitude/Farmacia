# Farmácia SuperAmplitude

Sistema de gestão de farmácia, atendimento e delivery em **https://farmacia.superamplitude.com**.

## Domínios e storage

- Portal público: `https://farmacia.superamplitude.com/`
- Painel: `https://farmacia.superamplitude.com/admin.php`
- API do chat: `https://farmacia.superamplitude.com/api/chat.php`
- CDN pública de imagens: `https://img.farmacia.superamplitude.com/`
- Cloudflare R2 account: `a26bcc0f570221207e6e66981adae363`
- Bucket: `superamplitude`
- Endpoint S3: `https://a26bcc0f570221207e6e66981adae363.r2.cloudflarestorage.com`
- Catálogo R2 informado: `https://catalog.cloudflarestorage.com/a26bcc0f570221207e6e66981adae363/superamplitude`
- Raiz de produção: `/home/superamplitude/htdocs/farmacia.superamplitude.com`
- Estado privado: `/home/superamplitude/.farmacia`

## Módulos

- Loja pública responsiva, busca, carrinho e checkout.
- Catálogo global sincronizado com dados oficiais da Anvisa.
- Catálogo, estoque e preço separados por farmácia.
- Recebimento privado de receitas e fila de avaliação farmacêutica.
- Delivery/retirada, zonas, taxas, ETA e estados operacionais.
- Super Admin, Admin da farmácia e perfis de funcionários.
- Chat com IA para busca interna, cards de medicamentos e informação geral.
- Auditoria e trilha operacional.
- Imagens centralizadas no Cloudflare R2 e servidas pelo domínio `img.farmacia.superamplitude.com`.

## Cloudflare R2

Os identificadores públicos estão no `.env.example`. As credenciais de escrita **não** ficam no GitHub. Configure somente no `.env` privado da VPS:

```text
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
```

Com essas duas credenciais, `src/R2Storage.php` pode gravar objetos no bucket `superamplitude`. As URLs públicas geradas usam `https://img.farmacia.superamplitude.com/<chave>`.

## Segurança

Receitas são armazenadas fora da raiz pública em `/home/superamplitude/.farmacia/uploads`. A IA é informativa e não substitui médico ou farmacêutico, não prescreve e não define dose individual.

## Deploy

O workflow `.github/workflows/deploy.yml` usa o runner self-hosted do repositório. Com ele online, qualquer push em `main` executa `deploy/bootstrap.sh`.
