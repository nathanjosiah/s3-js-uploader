# S3 JS Uploader

### Allows a JS client to upload any size files to a private s3 bucket using limited temporary AWS credentials that only have permission to directly upload to a private folder via STS IAM role assumption 

This example assumes that the environment already contains valid AWS authentication such as environment variables `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`. 

The role of the authenticated used needs to have IAM permission for `sts:assumeRole` on the `RoleArn` (IAM policy resource). In addition, the role specified by `RoleArn` needs to have the authenticated used configured as a trusted entity that is allowed to assume the role. The assumed role must have at least the same amount of permissions specified by the `Policy` but it cannot be less. 

Upon assumption of the role, the JS client will only have the maximum permissions specified by the inline JSON `Policy` which, in this case, is write access to a configurable bucket/path.      