# Contributing

Contributions and issue reports are welcome.

## Development

1. Install the module in a Drupal 10.3 or Drupal 11 development site.
2. Configure Drupal AI providers for Text-to-Image and Image-to-Image.
3. Install development dependencies with `composer install`.
4. Run `composer validate --strict` and `vendor/bin/phpcs`.
5. Run PHP syntax checks and validate the module's YAML files.
6. Test generation, refinement, access control, Media Library selection,
   queued video processing, downloads, and Media publishing.

Do not commit API keys, generated images, local settings, or vendor packages.
Changes should follow Drupal coding standards and include focused verification.

## Releases

For every tagged release:

1. Update `CHANGELOG.md` and `README.md`, validate a clean release checkout,
   commit the release, and create an annotated version tag.
2. Push the release commit and tag to both GitHub (`origin`) and Drupal.org
   (`drupal`).
3. Push or update the `1.0.x` development branch on both remotes.
4. Create the matching release on the Drupal.org project page using the
   changelog entry as its release notes.

## Reporting security issues

Do not open public issues for suspected security vulnerabilities. Follow the
security-reporting instructions in `SECURITY.md`.
