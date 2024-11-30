# My Private Stuff

This repository contains tools for building static HTML pages, some of which are
password-protected. It also contains other tools to help me secure my private
affairs.

The website contains some private information I may need if I lost my phone
while traveling the world. For now it contains:

* My 2FA recovery codes
* Some documents (Passport, Driver license, etc)
* Emergency contacts
* Administrative contacts (Bank, Insurance, etc)

## How it works?

* It uses [castor](https://castor.jolicode.com/) as a build tool
  * with some PHP tools, like twig to render the HTML
* It uses [staticrypt](https://github.com/robinmoisson/staticrypt) to encrypt the
  HTML page with a password

**The demo is partially deployed on github pages**:
[https://lyrixx.github.io/private-stuff/](https://lyrixx.github.io/private-stuff/)

Even if the page is encrypted, I don't want to deploy real data there. So I just
put some dummy data.

The real page is deployed somewhere else 👀 ... On cloudflare pages/worker, with
another password protection.

### Integration with cloudflare workers

Cloudflare allows to deploy static HTML, and also workers. Workers are a way to
run some code at edge (on Cloudflare infrastructure). So I can deploy the HTML
page and add another security layer at the HTTP level.

I followed this [great
post](https://dev.to/charca/password-protection-for-cloudflare-pages-8ma)  to
setup the password protection.

>[!NOTE]
> This part is optional. If you don't want to use cloudflare, you can just use
> the artifacts generated in `dist/public` and deploy them on any static
> hosting.

## Requirements

* [castor](https://castor.jolicode.com/)
* [nodejs](https://nodejs.org/)

For development:

* [docker](https://www.docker.com/)
* [mkcert](https://github.com/FiloSottile/mkcert)

## Usage

1. copy `.env` to `.env.local` and
   1. set a really strong passwords
   2. `CFP_` are not needed if you don't plan to deploy to Cloudflare Page
   3. set `APP_ENV=prod` to use your own data
2. copy `data/websites.yaml.dist` to `data/websites.yaml` and fill it with your
   data
3. do the same with `data/administrative_contacts.yaml.dist` and
   `emergency_contacts.yaml.dist`
4. run `castor build --no-open`
5. deploy `dist/public/` directory somewhere on the internet

    >[!NOTE]
    > If plan to use cloudflare, just use `castor deploy`

## Play with the stack

If you want to play with the stack locally, you'll need to resolve
`private-stuff.test` to local host:

```
echo "127.0.0.1 private-stuff.test" | sudo tee -a /etc/hosts
```

then run

```
castor build
```

It will, if needed:

* install JS vendor
* build all static content
* create new SSL certificats
* create docker image
* start a docker container
* open in your favorite browser the project

You can also run the `castor` command to see all others available tasks.

## License

This repository is under the MIT license. See the complete license in the
[LICENSE](LICENSE) file.

## Plan for the future

I would like to setup a web page with all my others private stuff (main
password, bank account, etc) in case something really bad happens. This page
will be protected with [Shamir's secret sharing
](https://en.wikipedia.org/wiki/Shamir%27s_secret_sharing).
