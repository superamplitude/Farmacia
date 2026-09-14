# Runner self-hosted — Farmácia

O repositório usa o runner self-hosted associado a `superamplitude/Farmacia`.

Com o runner **Online**, um push em `main` executa automaticamente:

```bash
bash deploy/bootstrap.sh
```

O deploy publica em:

```text
/home/superamplitude/htdocs/farmacia.superamplitude.com
```

URL pública:

```text
https://farmacia.superamplitude.com
```

Estado privado:

```text
/home/superamplitude/.farmacia
```

O token de registro do runner é usado somente no servidor e nunca deve ser gravado no repositório.
