const fs = require('fs');
const path = require('path');
const core = require('@actions/core');
const github = require('@actions/github');

async function run() {
  try {
    const token = process.env.GITHUB_TOKEN;
    if (!token) {
      core.setFailed('GITHUB_TOKEN or BOT_TOKEN not provided');
      return;
    }

    const eventPath = process.env.GITHUB_EVENT_PATH;
    if (!eventPath || !fs.existsSync(eventPath)) {
      core.setFailed('GITHUB_EVENT_PATH not found');
      return;
    }

    const payload = JSON.parse(fs.readFileSync(eventPath, 'utf8'));
    // get comment body (issue_comment or pull_request_review_comment)
    const comment = payload.comment && payload.comment.body ? payload.comment.body : '';
    const commenter = payload.comment && payload.comment.user ? payload.comment.user.login : null;
    if (!comment) {
      console.log('No comment body, exiting.');
      return;
    }

    // Listen for the command trigger
    if (!/@copilot\s+implement/i.test(comment)) {
      console.log('No @copilot implement found in comment, exiting.');
      return;
    }

    // Optional: restrict who can trigger the bot
    const allowedUsers = process.env.ALLOWED_USERS ? process.env.ALLOWED_USERS.split(',') : null;
    if (allowedUsers && commenter && !allowedUsers.includes(commenter)) {
      console.log(`User ${commenter} not in allowed users. Exiting.`);
      return;
    }

    const repoFull = process.env.GITHUB_REPOSITORY;
    const [owner, repo] = repoFull.split('/');
    const octokit = github.getOctokit(token);

    // Parse FILE blocks of the form:
    // FILE: path/to/file
    // ```newfile
    // <entire new file contents>
    // ```
    const fileBlockRegex = /FILE:\s*(.+?)\s*```newfile\s*([\s\S]*?)```/gmi;
    let match;
    const files = [];
    while ((match = fileBlockRegex.exec(comment)) !== null) {
      const filePath = match[1].trim();
      const content = match[2].replace(/\r\n|\r/g, '\n');
      files.push({ path: filePath, content });
    }

    if (files.length === 0) {
      console.log('No FILE blocks parsed. Exiting.');
      return;
    }

    // Determine base branch
    const repoResp = await octokit.rest.repos.get({ owner, repo });
    const defaultBranch = repoResp.data.default_branch;

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const branchName = `copilot/auto-${timestamp}`;

    // Create branch from default branch
    const refData = await octokit.rest.git.getRef({
      owner, repo, ref: `heads/${defaultBranch}`
    });
    const sha = refData.data.object.sha;

    await octokit.rest.git.createRef({
      owner, repo, ref: `refs/heads/${branchName}`,
      sha
    });

    // For each file, create or update via the contents API on that branch
    for (const f of files) {
      const contentBase64 = Buffer.from(f.content, 'utf8').toString('base64');
      try {
        // Try to get the file to see if it exists (to include sha when updating)
        const existing = await octokit.rest.repos.getContent({
          owner, repo, path: f.path, ref: defaultBranch
        });
        const fileSha = existing.data.sha;

        await octokit.rest.repos.createOrUpdateFileContents({
          owner, repo, path: f.path,
          message: `copilot: update ${f.path} (triggered by comment)`,
          content: contentBase64,
          branch: branchName,
          sha: fileSha
        });
      } catch (err) {
        if (err.status === 404) {
          // file doesn't exist -> create
          await octokit.rest.repos.createOrUpdateFileContents({
            owner, repo, path: f.path,
            message: `copilot: add ${f.path} (triggered by comment)`,
            content: contentBase64,
            branch: branchName
          });
        } else {
          throw err;
        }
      }
    }

    // Create a PR
    const prTitle = `Automated: apply @copilot changes (${new Date().toISOString().slice(0,10)})`;
    // Sanitize comment content to prevent markdown injection
    const sanitizedComment = comment.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const prBody = `This PR was opened automatically from a @copilot implement comment by @${commenter}.\n\nOriginal comment:\n\n> ${sanitizedComment.split('\n').join('\n> ')}`;

    const pr = await octokit.rest.pulls.create({
      owner, repo,
      title: prTitle,
      body: prBody,
      head: branchName,
      base: defaultBranch
    });

    // Comment on the PR with a link
    await octokit.rest.issues.createComment({
      owner, repo, issue_number: pr.data.number,
      body: `Created by automation in response to comment by @${commenter}.`
    });

    console.log(`Created PR ${pr.data.html_url}`);
  } catch (error) {
    core.setFailed(error.message);
  }
}

run();
