# PageQuality
A MediaWiki extension to monitor and improve Page Quality, by several pre-defined metrics, such as:
- Line length
- Paragraph length
- Number of items in a list
- Etc. (TBD: make this list comprehensive)

## Special pages
- Special:PageQuality: main dashboard
- Special:PageQuality/reports: detailed reports
- Special:PageQuality/history: allows comparing progress over time
- Special:PageQuality/settings: for changing various metrics.

## Dependencies
This extension directly embeds Bootstrap 3's styles for badge, panels and list-groups,
which may cause issues with skins that use Bootstrap.

## Excluding elements on the page
Elements with the CSS class `.pagequality-ignore` will be excluded from the audits. 

## Settings
- `wgPageQualityNamespaces` is an array of namespaces where the extension will work. The default `[ NS_MAIN ]`.

## Permissions
- "viewpagequality" is required to watch the list of issues and use the API.
  By default, this is applied to everyone.
- "configpagequality" is required to edit the settings of this extension.
  By default, this is applied to sysops.
- The extension also defines the group "pagequality-admin", with the "configpagequality" permission.

## Todo
- The option to limit by dates in the main report makes no sense currently, as it also shows scores per scorer, which
  aren't saved to the log. The following should probably be done:
  - Remove the individual scorers from the table [done]
  - Allow viewing the individual scores for a specific page when clicking a link in the table
- The current "limit by dates" doesn't even work. It's definitely not correctly selecting the oldest and newest log entries.
- Lint the code
- Scope styles imported from Bootstrap or replace them with MediaWiki's native elements
- Perhaps: Make the issues' sidebar look and behave like Google Docs' "Version history" drawer

## Changlog
1.0.0a:
- "Red" pages are now only those that have a red-level issue *and also* a minimum score.
- It is highly recommended to empty all the extension's tables prior to upgrading, as the compatibility with the older schema is wonky.
- The individual scorer results were removed from the reports table
- the declines/improvements reports aren't really working right now.
- `wgPageQualityNamespaces` is an array of namespaces where the extension will work. The default `[ NS_MAIN ]`. 
  This also speeds up the regeneration of all scores.
- Remove the "configpagequality" from the sysop group, leave it only to the "pagequality-admin" group.

