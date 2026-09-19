# Release checklist

1. Confirm that the working tree contains no site-specific maps, runtime data,
   credentials, logs or generated files.
2. Update `blueprints.yaml` and `CHANGELOG.md` for the release version.
3. Run `composer validate --strict`, `composer check` and `git diff --check`.
4. Install the plugin in a clean Grav 2 site and verify the configuration and
   redirect cases listed in `AGENTS.md`.
5. Commit the release, create an annotated tag such as `0.1.0`, and push the
   tag to the public repository.
6. Create the corresponding GitHub release using the changelog entry.

Composer versions are taken from the Git tag. Keep the plugin version in
`blueprints.yaml` and the changelog in sync with that tag.
