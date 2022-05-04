#!/usr/bin/env sh
# abort on errors
set -e
# build
yarn run build

cd dist

date +'FORMAT'
date +'%m-%d-%Y %H:%M:%S'
commit_date=$(date +'%m-%d-%Y %H:%M:%S')

# if script not run try "chmod +x deploy.sh" on terminal
git init
git add -A
git commit -m "dev: Auto deploy on ${commit_date}" --no-verify
git push -f git@github.com:lsnepomuceno/laravel-a1-pdf-sign.git master:gh-pages
cd -
