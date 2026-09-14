# Runner self-hosted

O repositório já contém o workflow de produção. O token de registro do GitHub Runner deve ser usado apenas no terminal da VPS e nunca salvo neste repositório.

No diretório em que o GitHub Actions Runner foi extraído, registre-o para o repositório `superamplitude/Farmacia` com o comando fornecido pelo GitHub. Em seguida instale/inicie o serviço do runner conforme a distribuição Linux usada na VPS.

Depois de o runner aparecer como **Online**, um push na branch `main` executará automaticamente:

```bash
bash deploy/bootstrap.sh
```

O script publica em `/home/superamplitude/htdocs/www.superamplitude.com/Farmacia`, cria o diretório privado `/home/superamplitude/.farmacia`, sincroniza a base Anvisa e tenta sincronizar o manifesto de imagens.

Antes de liberar o site, edite `/home/superamplitude/.farmacia/.env` e configure pelo menos a senha do Super Admin e, quando disponíveis, as credenciais da IA e integrações externas.
