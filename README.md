# Farmácia SuperAmplitude

Sistema de gestão de farmácia e delivery para **`https://farmacia.superamplitude.com`**, preparado para múltiplas farmácias/clientes.

## Arquitetura de domínio

A Farmácia é um **subdomínio independente**. Não usa `/Farmacia` dentro de `www.superamplitude.com`.

- Portal: `https://farmacia.superamplitude.com/`
- Painel: `https://farmacia.superamplitude.com/admin.php`
- API do chat: `https://farmacia.superamplitude.com/api/chat.php`
- Imagens: `https://imagem.superamplitude.com/`
- Raiz de produção esperada: `/home/superamplitude/htdocs/farmacia.superamplitude.com`
- Estado privado: `/home/superamplitude/.farmacia`

## Módulos implementados

- Loja pública responsiva com busca e carrinho.
- Catálogo global baseado em dados abertos oficiais da Anvisa.
- Catálogo, estoque e preço separados por farmácia.
- Upload privado de receita fora da raiz pública do site.
- Fila de avaliação farmacêutica com aprovação/rejeição e auditoria.
- Delivery/retirada, zonas de entrega, taxa, faixa de gratuidade e status operacional.
- Chat com IA para pesquisa interna e respostas informativas sobre medicamentos.
- Cards de medicamento no chat com nome, princípio ativo, disponibilidade, preço e foto.
- Imagens preparadas para `https://imagem.superamplitude.com` por manifesto controlado.
- Super Admin para cadastrar farmácias/clientes.
- Admin da farmácia para catálogo, estoque, equipe e delivery.
- Perfis de funcionário: farmacêutico, atendimento, estoque e delivery.
- Logs de auditoria.
- Workflow de deploy por GitHub Actions em runner self-hosted.

## Segurança clínica e de dados

A IA é informativa: não diagnostica, não prescreve, não define dose individual e não orienta iniciar/interromper tratamento. Perguntas clínicas sensíveis devem ser validadas pelo farmacêutico ou médico.

Receitas ficam em `/home/superamplitude/.farmacia/uploads`, fora da raiz pública. O sistema usa validação de MIME, limite de tamanho, hash SHA-256, sessão segura, CSRF e RBAC.

## Anvisa / Bulário / SNCR

O catálogo é sincronizado do conjunto oficial de medicamentos da Anvisa e mantém acesso ao Bulário Eletrônico oficial. O SNCR permanece desativado (`SNCR_ENABLED=0`) até existirem credenciais e documentação aplicáveis à operação.

## Imagens

O projeto não raspa indiscriminadamente fotografias comerciais de terceiros. Configure `IMAGE_MANIFEST_URL` com imagens próprias/licenciadas ou de fonte autorizada e execute `php scripts/sync_images.php`.

## Deploy

O workflow `.github/workflows/deploy.yml` usa runner `self-hosted, linux, x64`. Com o runner online, qualquer push em `main` executa `deploy/bootstrap.sh` e publica diretamente no subdomínio.

Nunca grave token de runner, chaves da IA, senhas, credenciais de pagamento ou Cloudflare no repositório.
