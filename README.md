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

## Arquitetura de produção

A Farmácia é um site PHP independente no CloudPanel e usa um **site user dedicado `farmacia`**, em vez de compartilhar o usuário de outro portal. Isso segue o isolamento de sites do CloudPanel e evita permissões cruzadas.

- Código: `/home/farmacia/htdocs/farmacia.superamplitude.com`
- Estado privado: `/home/farmacia/.farmacia`
- Banco SQLite: `/home/farmacia/.farmacia/farmacia.sqlite`
- Receitas: `/home/farmacia/.farmacia/uploads`
- Runner: `/opt/actions-runner-farmacia`
- Serviço do runner: `github-actions-farmacia`

## Módulos

- Loja pública responsiva, busca, carrinho e checkout.
- Catálogo global sincronizado com dados oficiais da Anvisa.
- Catálogo, estoque e preço separados por farmácia.
- Recebimento privado de receitas e fila de avaliação farmacêutica.
- Delivery/retirada, zonas, taxas, ETA e estados operacionais.
- Super Admin, Admin da farmácia e perfis de funcionários.
- Chat com IA para busca interna, cards de medicamentos e informação geral.
- Auditoria e trilha operacional.
- Imagens centralizadas no Cloudflare R2 e servidas por `img.farmacia.superamplitude.com`.

## Segurança e segredos

Segredos nunca são versionados. O arquivo real fica em `/home/farmacia/.farmacia/.env`. O bootstrap gera automaticamente `APP_KEY` e uma senha forte inicial do Super Admin quando ainda não existem. A cópia de recuperação da credencial inicial fica somente em `/root/.farmacia-superadmin` com permissão restrita.

As credenciais de escrita do R2 devem existir apenas no `.env` privado:

```text
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
```

O deploy executa um PUT assinado de teste quando as duas credenciais estão configuradas.

## Primeiro bootstrap da VPS

Depois que o runner estiver ativo, uma única execução como `root` provisiona o site PHP no CloudPanel, cria backup, instala o helper de permissões restrito, migra eventual estado legado, sincroniza o repositório, importa a Anvisa e executa a validação end-to-end:

```bash
cd /root && \
curl -fsSL https://raw.githubusercontent.com/superamplitude/Farmacia/main/deploy/repair-permissions.sh \
-o /root/repair-farmacia.sh && \
chmod +x /root/repair-farmacia.sh && \
/root/repair-farmacia.sh
```

Nenhum token de runner é necessário nessa etapa.

## Deploy contínuo

Após o bootstrap inicial, o workflow `.github/workflows/deploy.yml` usa o runner `farmacia-production` com labels `self-hosted`, `farmacia`, `production`. O workflow faz preflight, repara ACLs por um helper root restrito, sincroniza `main`, valida PHP, importa/atualiza a base Anvisa, roda self-test e verifica origin + Cloudflare.

## Limites funcionais ainda explícitos

- Meios de pagamento exibidos no checkout ainda não representam gateways com confirmação por webhook.
- A IA generativa somente fica ativa quando `AI_ENABLED=1` e endpoint/chave/modelo são configurados no `.env` privado; sem isso o sistema usa busca/fallback informativo.
- SNCR permanece desativado até credenciais e integração aplicáveis estarem disponíveis.
- R2 de escrita só é considerado validado após `R2_WRITE=ok`.
