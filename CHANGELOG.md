# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

- The seven forum tables (`forum_groups`, `forums`, `threads`, `posts`, `post_reports`, `post_moderation_logs`, `thread_moderation_logs`), matching the shape of the forum they were extracted from index for index, including the generated `threads.last_activity_at` column, the FULLTEXT indexes and the tuned listing indexes.
- Models, enums and a model-resolution helper (`forumkit-forums.models`, overridable under `forumkit.models`).
- A schema snapshot so `forumkit:adopt` can adopt an existing database; a site's extra columns are declared with a core `SchemaExtension`.
