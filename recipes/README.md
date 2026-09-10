# Optional demo recipes

These recipes are opt-in. Installing or updating AI Image Studio does not apply
them. Use Drupal 11.1 or later with core's recipe content importer; the recipes
are tested on Drupal 11.4. Download AI Image Studio and its dependencies before
applying a recipe. Recipes enable modules but do not download Composer packages.

## Studio examples

`ai_image_studio_demo` installs three reusable Start prompts (dragons over
Kakadu, a ceramic cup and a lighthouse) and an empty **Demo: Dragons over Kakadu**
session. Open the session, select the matching Demo Start prompt, choose a Style
and generate the first version. The normal installation already supplies Styles
and After prompts.

From the DDEV project root, with Drush's `recipe` command available (recipe paths
are relative to Drupal's web root):

```sh
ddev drush recipe modules/contrib/ai_image_studio/recipes/ai_image_studio_demo
```

## Studio and storyboard examples

`ai_image_studio_storyboard_demo` includes the Studio recipe and enables
AI Storyboard and its dependencies, including AI Image Studio VBO, Token and
Views Bulk Operations. Ensure the contributed dependencies are downloaded first:

```sh
ddev composer require drupal/token drupal/views_bulk_operations
ddev drush recipe modules/contrib/ai_image_studio/recipes/ai_image_studio_storyboard_demo
```

It adds a draft **Demo: Dragons over Kakadu** storyboard with an original script,
creative brief, continuity instructions, one scene and three editable shots.
Open `/admin/content/ai-storyboard` to review it, choose your configured image
and video models, and generate frames or video when ready. The Studio session
and storyboard are independent examples; neither contains generated versions.

No API calls, queued generation jobs, media files, API keys, model defaults or
role permissions are created by these recipes. Generation uses the site's
providers and may incur their normal charges when an editor runs it.

Content is owned by the administrator account selected by Drupal's content
importer. Existing access rules apply; an administrator can review the examples.
The recipes do not create demo users or grant editors access to another owner's
session.

Stable UUIDs prevent duplicate content on repeat application. Existing demo
content is skipped and edited prompt configuration is preserved. Deleting a
demo item allows the recipe to recreate it on the next application. The
`aiis_demo_*` machine names are reserved for these examples; rename conflicting
content before applying the recipe.

Recipes have no uninstall operation. Remove unwanted examples through the
normal session, storyboard and AI Prompt management screens. This leaves any
modules enabled by the recipe installed.

See Drupal's [recipe application guide](https://www.drupal.org/docs/extending-drupal/drupal-recipes/how-to-download-and-apply-drupal-recipes)
and [configuration preservation behaviour](https://www.drupal.org/node/3478662).
