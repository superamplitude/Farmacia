# Farmácia SuperAmplitude

Sistema web de farmácia para publicação em `https://superamplitude.com/Farmacia`.

## Escopo

- Catálogo de medicamentos e produtos.
- Importação da base oficial de medicamentos da Anvisa.
- Importação da tabela CMED para referência de preço máximo.
- Busca por nome comercial, princípio ativo, laboratório e registro Anvisa.
- Classificação de venda: MIP, prescrição, retenção e controle especial.
- Carrinho, pedido, estoque, clientes e upload de receita.
- Bloqueio de checkout para itens que exigem validação farmacêutica.
- Painel administrativo, auditoria e fila de importação.
- Gancho opcional para atendimento/busca assistida por IA.
- Deploy automático em `/home/superamplitude/htdocs/www.superamplitude.com/Farmacia` via runner self-hosted.

## Fontes oficiais

O importador utiliza os conjuntos oficiais da Anvisa e a lista de preços CMED. Imagens comerciais de embalagens não fazem parte desses conjuntos; o sistema usa imagem neutra por padrão e aceita ingestão de imagens oficiais/licenciadas de fabricantes ou distribuidores.

## Segurança

Credenciais, tokens e chaves não devem ser commitados. Use variáveis de ambiente e arquivos `.env` fora do controle de versão.
