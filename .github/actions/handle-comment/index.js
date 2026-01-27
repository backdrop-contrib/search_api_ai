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
    // content here
    // ```
    const fileBlockRegex = /FILE:\s*(.+?)\n```(?:\w+)?\n([\s\S]+?)```/gi;
    let match;
    const filesToUpdate = [];

    while ((match = fileBlockRegex.exec(comment)) !== null) {
      const filePath = match[1].trim();
      const fileContent = match[2];
      filesToUpdate.push({ path: filePath, content: fileContent });
    }

    if (filesToUpdate.length === 0) {
      console.log('No FILE blocks found in comment, exiting.');
      return;
    }

    console.log(`Found ${filesToUpdate.length} file(s) to update:`, filesToUpdate.map(f => f.path));

    // Get the default branch
    const { data: repoData } = await octokit.rest.repos.get({ owner, repo });
    const defaultBranch = repoData.default_branch;

    // Create a new branch
    const timestamp = Date.now();
    const branchName = `copilot-implement-${timestamp}`;
    
    const { data: refData } = await octokit.rest.git.getRef({
      owner,
      repo,
      ref: `heads/${defaultBranch}`,
    });
    const baseSha = refData.object.sha;

    await octokit.rest.git.createRef({
      owner,
      repo,
      ref: `refs/heads/${branchName}`,
      sha: baseSha,
    });

    console.log(`Created branch: ${branchName}`);

    // Update/create files on the new branch
    for (const file of filesToUpdate) {
      let fileSha = null;
      try {
        const { data: existingFile } = await octokit.rest.repos.getContent({
          owner,
          repo,
          path: file.path,
          ref: branchName,
        });
        if (!Array.isArray(existingFile)) {
          fileSha = existingFile.sha;
        }
      } catch (error) {
        // File doesn't exist, will be created
        console.log(`File ${file.path} does not exist, will create it.`);
      }

      const contentBase64 = Buffer.from(file.content).toString('base64');
      await octokit.rest.repos.createOrUpdateFileContents({
        owner,
        repo,
        path: file.path,
        message: `Update ${file.path} via @copilot implement`,
        content: contentBase64,
        branch: branchName,
        sha: fileSha || undefined,
      });

      console.log(`Updated/created file: ${file.path}`);
    }

    // Create a pull request
    const prTitle = `[Copilot] Implement changes from comment`;
    const prBody = `This PR was automatically created by the @copilot implement bot.\n\nTriggered by: @${commenter}\n\nFiles updated:\n${filesToUpdate.map(f => `- ${f.path}`).join('\n')}`;

    const { data: pr } = await octokit.rest.pulls.create({
      owner,
      repo,
      title: prTitle,
      head: branchName,
      base: defaultBranch,
      body: prBody,
    });

    console.log(`Created PR: ${pr.html_url}`);

    // Optionally, add a comment back to the original issue/PR
    let commentUrl = null;
    if (payload.issue) {
      // This is an issue_comment event
      commentUrl = payload.issue.comments_url;
    } else if (payload.pull_request) {
      // This is a pull_request_review_comment event
      commentUrl = payload.pull_request.comments_url;
    }

    if (commentUrl) {
      await octokit.request(`POST ${commentUrl}`, {
        body: `✅ Created PR #${pr.number} to implement your changes: ${pr.html_url}`,
      });
    }

    core.setOutput('pr_number', pr.number);
    core.setOutput('pr_url', pr.html_url);

  } catch (error) {
    core.setFailed(`Action failed: ${error.message}`);
    console.error(error);
  }
}

run();
