# Выкладка из GitHub Actions

Тег `vX.Y.Z` в `main` → workflow «Выкладка» (`.github/workflows/deploy.yml`):
батарея на PHP 8.4 → сборка `build_dist.php` → архив уходит на сервер по SSH →
`sso-deploy` на сервере делает копию, накладывает выпуск, выравнивает права,
применяет миграции и проверяет живую установку. Вручную — вкладка Actions →
«Выкладка» → Run workflow: пустое поле — последний выпуск, или конкретный тег.

## Ключ выкладки умеет одно

Ключ GitHub Actions не даёт оболочку на сервере. В `authorized_keys` он стоит с
принудительной командой:

```
command="/usr/local/sbin/sso-deploy",restrict ssh-ed25519 AAAA… github-actions-deploy
```

Что бы ни попросил клиент, запускается только `sso-deploy`, и он понимает две
команды: `check` и `deploy X.Y.Z` (архив — на stdin). `restrict` запрещает
терминал и все пробросы. Утечка ключа не даёт входа на сервер — только
возможность выложить архив, который пройдёт проверку версии.

## Установка на сервер (один раз, от root)

```bash
install -m 755 sso-deploy.sh /usr/local/sbin/sso-deploy
install -m 600 sso-deploy.conf.example /etc/sso-deploy.conf   # и вписать свои значения
ssh-keygen -t ed25519 -N "" -C github-actions-deploy -f deploy_key   # на своей машине
# в /root/.ssh/authorized_keys — строка из deploy_key.pub с префиксом command=…,restrict (см. выше)
SSH_ORIGINAL_COMMAND=check /usr/local/sbin/sso-deploy                # проверка: «связь есть; на сайте X.Y.Z»
```

## Секреты репозитория

Settings → Secrets and variables → Actions → New repository secret:

| Секрет | Что в нём |
|---|---|
| `DEPLOY_SSH_KEY` | закрытый ключ `deploy_key` целиком, с строками BEGIN/END |
| `DEPLOY_TARGET` | `root@адрес-сервера` |
| `DEPLOY_KNOWN_HOSTS` | строка `ssh-keyscan -t ed25519 адрес-сервера` — сверка ключа сервера |

Журнал Actions у публичного репозитория открыт всем: `sso-deploy` не печатает
ни путей, ни домена, ни адресов, а адрес сервера workflow дополнительно маскирует.
