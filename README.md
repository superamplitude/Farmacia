# Farmácia SuperAmplitude

Sistema de gestão de farmácia e delivery para `https://superamplitude.com/Farmacia`, preparado para múltiplas farmácias/clientes.

## Módulos implementados

- Loja pública responsiva com busca e carrinho.
- Catálogo global baseado em dados abertos oficiais da Anvisa.
- Catálogo/estoque/preço separados por farmácia.
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

## Segurança e regra clínica

A IA é apenas informativa. O prompt de segurança impede diagnóstico, prescrição, definição de dose individual e orientação para iniciar/interromper tratamento. Perguntas clínicas sensíveis devem ser validadas pelo farmacêutico ou médico.

Arquivos de receita são armazenados em `/home/superamplitude/.farmacia/uploads`, fora do diretório público. O sistema usa validação de MIME, limite de tamanho, hash SHA-256, sessão segura, CSRF e RBAC.

## Anvisa / Bulário / SNCR

O catálogo é sincronizado do conjunto oficial de medicamentos da Anvisa. A aplicação mantém link para o Bulário Eletrônico oficial.

O SNCR está preparado por variáveis de ambiente, mas permanece desativado (`SNCR_ENABLED=0`) até que as funcionalidades e credenciais aplicáveis à operação da farmácia estejam efetivamente disponíveis. Não existe integração fictícia.

## Imagens

A Anvisa não fornece um catálogo completo de fotografias comerciais de todas as embalagens junto ao CSV de medicamentos. Por isso, o projeto não faz raspagem indiscriminada de imagens de terceiros. Use um manifesto JSON com imagens próprias, licenciadas, de fabricantes/distribuidores autorizados ou outra fonte com direito de uso.

Exemplo de manifesto:

```json
[
  {"registration":"1234567890123","path":"medicamentos/1234567890123.webp"}
]
```

Configure `IMAGE_MANIFEST_URL` e execute `php scripts/sync_images.php`.

## Deploy

Destino de produção:

```text
/home/superamplitude/htdocs/www.superamplitude.com/Farmacia
```

Estado privado:

```text
/home/superamplitude/.farmacia
```

O workflow `.github/workflows/deploy.yml` usa runner `self-hosted, linux, x64`. Depois que o runner estiver online, qualquer push em `main` executa `deploy/bootstrap.sh`.

Nunca grave token do runner, chave da IA, senha, credencial de pagamento ou credencial Cloudflare no GitHub. Configure tudo no `.env` privado da VPS.
