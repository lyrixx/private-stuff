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

`castor deploy` needs wrangler to be authenticated. Either run
`node_modules/.bin/wrangler login` once (OAuth, stored in `~/.config/.wrangler/`),
or put a `CLOUDFLARE_API_TOKEN` in `.env.prod.local`, plus a
`CLOUDFLARE_ACCOUNT_ID` if the token has access to several accounts.

`castor deploy` always deploys to the production branch of the Pages project
(`CFP_PRODUCTION_BRANCH`, `main` by default), whatever your local git branch,
and then checks that the site asks for a password. Pages *preview* deployments
(any other branch) do not receive the production secrets: `CFP_PASSWORD` would
be empty there and the middleware would let everyone in. Never create one with
real data, and consider enabling the Access policy for preview deployments in
the settings of the Pages project.

## Requirements

* [castor](https://castor.jolicode.com/)
* [nodejs](https://nodejs.org/)

For development:

* [docker](https://www.docker.com/)
* [mkcert](https://github.com/FiloSottile/mkcert)

## Usage

The project has two modes, selected by the `APP_ENV` variable:

* `dev` (the default): dummy data, default passwords, deploy is forbidden. This
  is what a fresh clone and the GitHub Pages workflow use. Nothing to configure.
* `prod`: your own data from `data/` and your own passwords.

To work in `prod` mode locally:

1. create `.env.local` with a single line: `APP_ENV=prod`
2. create `.env.prod.local` with your secrets (copy the keys from `.env`)
   1. set really strong passwords
   2. `CFP_` are not needed if you don't plan to deploy to Cloudflare Page

   Both files are gitignored, and `.env.prod.local` is only loaded in `prod`
   mode, so your secrets never leak into a `dev` build.
3. copy `data/recovery_codes.yaml.dist` to `data/recovery_codes.yaml` and fill
   it with your data
4. do the same with `data/administrative_contacts.yaml.dist` and
   `emergency_contacts.yaml.dist`
5. run `castor build --no-open`
6. deploy `dist/public/` directory somewhere on the internet

To temporarily switch mode for a single command, set the variable on the command
line, e.g. `APP_ENV=dev castor build` to check the templates with dummy data.

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
