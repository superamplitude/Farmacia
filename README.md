# Farmácia SuperAmplitude

Sistema de gestão de farmácia, catálogo Anvisa, atendimento, receitas, pedidos e delivery em **https://farmacia.superamplitude.com**.

## Produção

- Portal público: `https://farmacia.superamplitude.com/`
- Painel: `https://farmacia.superamplitude.com/admin.php`
- Acompanhamento de pedido: `https://farmacia.superamplitude.com/pedido.php?t=<token>`
- API do chat: `https://farmacia.superamplitude.com/api/chat.php`
- Webhook de pagamento: `https://farmacia.superamplitude.com/api/payment_webhook.php?provider=mercadopago`
- CDN pública de imagens: `https://img.farmacia.superamplitude.com/`
- Cloudflare R2 account: `a26bcc0f570221207e6e66981adae363`
- Bucket: `superamplitude`
- Endpoint S3: `https://a26bcc0f570221207e6e66981adae363.r2.cloudflarestorage.com`

## Arquitetura real da VPS

O site usa o usuário CloudPanel dedicado **`superamplitude-farmacia`**.

- Código/document root: `/home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com`
- Estado privado: `/home/superamplitude-farmacia/.farmacia`
- Banco SQLite: `/home/superamplitude-farmacia/.farmacia/farmacia.sqlite`
- Receitas: `/home/superamplitude-farmacia/.farmacia/uploads`
- Backups: `/home/superamplitude-farmacia/.farmacia/backups`
- Runner: `/opt/actions-runner-farmacia`
- Serviço: `github-actions-farmacia`
- Controle root restrito: `/usr/local/sbin/farmacia-vps-control`

## Módulos concluídos

- Busca pública no catálogo Anvisa, inclusive medicamentos ainda não ativados pela farmácia.
- Compra liberada somente quando a farmácia informa preço, estoque e produto ativo.
- Carrinho separado por farmácia, checkout, retirada e delivery.
- Zonas de entrega, taxa, faixa de frete grátis e ETA.
- Receitas PDF/JPG/PNG em armazenamento privado, hash SHA-256 e avaliação farmacêutica.
- Bloqueio operacional de pedidos sujeitos a avaliação até aprovação/rejeição.
- Super Admin multi-farmácia, administrador da farmácia e perfis de farmacêutico, atendimento, estoque e delivery.
- Cadastro operacional da farmácia, equipe, preço, estoque e imagens.
- Upload de imagem diretamente ao Cloudflare R2 quando as credenciais de escrita estão configuradas.
- Acompanhamento público de pedido por token aleatório não sequencial.
- Pagamento com dinheiro/cartão na entrega e integração pronta para PIX Mercado Pago.
- PIX com criação de pagamento, QR Code/copia-e-cola, sincronização de status e webhook.
- Chat com busca de catálogo e modo seguro de fallback; provedor generativo pode ser ativado pelo `.env` privado.
- Auditoria operacional e trilha de eventos de pagamento.
- Deploy contínuo pelo runner instalado na própria VPS, com preflight, importação Anvisa, lint, self-test e verificação HTTP.

## Segredos e integrações externas

Segredos nunca são versionados. O arquivo real é:

`/home/superamplitude-farmacia/.farmacia/.env`

O bootstrap gera `APP_KEY` e a senha inicial do Super Admin quando necessário.

### R2 de escrita

Para upload/sincronização de imagens:

```text
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
```

### PIX Mercado Pago

Para ativar PIX online:

```text
PAYMENT_PROVIDER=mercadopago
MERCADOPAGO_ACCESS_TOKEN=...
```

Sem a credencial, o checkout não exibe PIX e continua funcional com os meios presenciais.

### IA generativa

Para ativar o provedor compatível com API de chat:

```text
AI_ENABLED=1
AI_API_URL=...
AI_API_KEY=...
AI_MODEL=...
```

Sem essas variáveis, o chat continua em fallback seguro baseado no catálogo, sem inventar resposta clínica.

### SNCR

A integração permanece desligada por padrão e só deve ser habilitada quando houver endpoint/credenciais oficiais aplicáveis:

```text
SNCR_ENABLED=1
SNCR_BASE_URL=...
SNCR_CLIENT_ID=...
SNCR_CLIENT_SECRET=...
```

## Deploy contínuo

O workflow `.github/workflows/deploy.yml` usa o runner `farmacia-production` com labels `self-hosted`, `farmacia`, `production`. Ele opera diretamente na VPS e sincroniza `main` para:

`/home/superamplitude-farmacia/htdocs/farmacia.superamplitude.com`

O deploy executa preflight, ACL, PHP lint, importação/atualização Anvisa, sincronização de imagens quando configurada, self-test, teste de escrita R2 quando há credenciais e verificação HTTP pública/origin/admin.

## Critério de fechamento

A aplicação é considerada operacional quando o deploy retorna `FARMACIA_DEPLOY_OK` e a verificação retorna `VERIFY_STATUS=OK` com portal e admin HTTP 200. Integrações que dependem de contas de terceiros ficam automaticamente inativas até que suas credenciais privadas sejam instaladas na VPS; o código não simula sucesso quando uma credencial não existe.
