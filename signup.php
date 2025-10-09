<?php include 'header.php';?>
<!-------------------- CODE Starts HERE -------------------->

    <div class="card w-50 mx-auto my-4">
        <div class="card-header text-center bg-secondary bg-gradient py-3 h3 text-white">REGISTRATION</div>
        <div class="card-body bg-secondary-subtle">
            <p>First Name:
                <input type="text" id="first_name" class="form-control" />
                <small id="err_first_name" class="text-danger"></small>
            </p>

            <p>Last Name:
                <input type="text" id="last_name" class="form-control" />
                <small id="err_last_name" class="text-danger"></small>
            </p>

            <p>Username:
                <input type="text" id="user_name" class="form-control" />
                <small id="err_user_name" class="text-danger"></small>
            </p>

            <p>E-mail:
                <input type="email" id="email" class="form-control" />
                <small id="err_email" class="text-danger"></small>
            </p>

            <p>Password:
                <input type="password" id="password1" class="form-control" />
                <small id="err_password1" class="text-danger"></small>
            </p>

            <p>Password (again):
                <input type="password" id="password2" class="form-control" />
                <small id="err_password2" class="text-danger"></small>
            </p>

            <div id="registerError" class="text-danger mb-3" role="alert" style="display:none;"></div>

            <div>
                <input type="button" value="Submit" class="btn btn-success" onclick="registerAccount()" />
                &nbsp&nbsp&nbsp

                <input type="button" value="Go to login" class="btn btn-primary" onclick="toLogin()" />
                &nbsp&nbsp&nbsp

                <input type="button" value="Home" class="btn btn-primary" onclick="toIndex()" />
                &nbsp&nbsp&nbsp
            </div>
        </div>
    </div>
<!-------------------- CODE ENDS HERE -------------------->
<?php include 'footer.php'; ?>
