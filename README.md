## Configuration
### Resource directories
src/ : mark as source

stubs/ : mark as resource root

tests/unit/: mark as test sources root

### GitHub token
Inside of docker-files/php-extract/, there is a file called token.dist. Copy this to the same folder, call it "token" and add in a GitHub OAuth token. This is copied into the php-extract container so that it won't complain about API rate limits when updating composer for whatever reason.

## Running stuff
You'll probably want a docker container with php-ast in it and php 7.

## Examples
You'll have to procure those yourself.