# My Private Stuff

This repository contains some toolings to build a protected HTML pages with a
password.

Basically, this page contains some private stuff I may need if I lost my phone
while traveling the world. For now it contains:

* My 2FA recovery codes

## How it works?

* It uses [castor](https://castor.jolicode.com/) as a build tool
  * with some PHP tools, like twig to render the HTML
* It uses [staticrypt](https://github.com/robinmoisson/staticrypt) to crypt the
  HTML page with a password

**The demo is partially deployed on github pages**:
[https://lyrixx.github.io/private-stuff/](https://lyrixx.github.io/private-stuff/)

Even if the page is encrypted, I don't want to deploy real data there. So I just
put some dummy data.

The real page is deployed somewhere else 👀 ... On cloudflare pages/worker

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

## Usage

1. copy `.env` to `.env.local` and set a really strong password
2. copy `data/websites.yaml.dist` to `data/websites.yaml` and fill it with your
   data
3. run `castor build`
4. deploy `dist/public/index.html` somewhere

If you use cloudflare, you can deploy the `dist/` folder to a worker.

## License

This repository is under the MIT license. See the complete license in the
[LICENSE](LICENSE) file.

## Plan for the future

I would like to setup a web page with all my others private stuff (main
password, bank account, etc) in case something really bad happens. This page
will be protected with [Shamir's secret sharing
](https://en.wikipedia.org/wiki/Shamir%27s_secret_sharing).
