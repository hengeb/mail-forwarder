# mail-forwarder

forward mail from generic address to restricted accounts

## Summary

`mail-forwarder` lets you set up a generic mail address like `forward@example.org` for forwarding mails to undisclosed addresses or accounts with restricted access.

In the example of `forward@example.org`, mails to `forward+username@example.org` would be forwarded to `username@another.example.org` where `another.example.org` is configurable.

## Install

Run `composer install`

Copy `config.json.sample` to `config.json` and change it for your needs, see section *Configuration* below.

Let a cron job run `php run.php` periodically.

## Configuration

`config.json` must be in valid JSON format.

Compare the entries in `config.json.sample` with the example in the above section *Summary*.

`source` contains the IMAP configuration (currently, only `"type": "imap"` is supported) for the generic mail address.

`target` contains the configuration for the forwarding (currently, only `"type": "smtp"` is supported).

You can set up a list of trusted mail addresses that are allowed to send mails to the forwarding service. Leave the list empty to allow all incoming mails. Mails from any other origin will be reported to the `abuseAddress`.
