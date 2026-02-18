#!/bin/bash
# Script for Git Push (zsh/bash)
# Pushes to origin (default: https://github.com/yson46693-droid/almostafa.git)

cd "$(dirname "$0")"

echo "\033[33mAdding files...\033[0m"
git add -A

if [ -z "$(git status --porcelain)" ]; then
    echo "\033[36mNo changes to commit\033[0m"
    exit 0
fi

msg="Update - $(date '+%Y-%m-%d %H:%M')"
echo "\033[33mCreating commit...\033[0m"
git commit -m "$msg"

if [ $? -ne 0 ]; then
    echo "\033[31mError creating commit\033[0m"
    exit 1
fi

echo "\033[33mFetching latest changes from remote...\033[0m"
git fetch origin main

local_commit=$(git rev-parse HEAD)
remote_commit=$(git rev-parse origin/main 2>/dev/null)
if [ $? -eq 0 ] && [ "$local_commit" != "$remote_commit" ]; then
    echo "\033[33mRemote has new changes. Pulling changes...\033[0m"
    git pull origin main --no-rebase
    if [ $? -ne 0 ]; then
        echo "\033[31mError during pull. You may need to resolve conflicts manually.\033[0m"
        echo "\033[33mRun 'git pull origin main' manually to resolve conflicts.\033[0m"
        exit 1
    fi
    echo "\033[32mPull completed successfully!\033[0m"
fi

echo "\033[33mPushing to GitHub...\033[0m"
git push origin main

if [ $? -eq 0 ]; then
    echo "\033[32mPush completed successfully!\033[0m"
else
    echo "\033[31mError during push\033[0m"
    echo "\033[33mPlease check:\033[0m"
    echo "\033[33m1. Internet connection\033[0m"
    echo "\033[33m2. Authentication credentials\033[0m"
    echo "\033[33m3. Push permissions\033[0m"
    echo "\033[33m4. If conflicts exist, resolve them and try again\033[0m"
    exit 1
fi
