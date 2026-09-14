# Runner self-hosted — Farmácia

O repositório usa o runner dedicado `farmacia-production`, registrado em `superamplitude/Farmacia` com labels `self-hosted`, `farmacia` e `production`.

Depois do bootstrap inicial como `root`, cada push em `main` executa automaticamente o fluxo de produção:

```text
preflight -> helper restrito de permissões -> deploy -> self-test -> verify
```

Código de produção:

```text
/home/farmacia/htdocs/farmacia.superamplitude.com
```

Estado privado:

```text
/home/farmacia/.farmacia
```

URL pública:

```text
https://farmacia.superamplitude.com
```

O primeiro bootstrap provisiona o site PHP no CloudPanel com um usuário de site exclusivo `farmacia`. Isso mantém o isolamento de filesystem recomendado pelo CloudPanel.

O token de registro do runner é usado somente para registrar o runner e nunca deve ser gravado no repositório. O deploy normal não precisa reutilizar esse token.
