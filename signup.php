<?php include 'header.php';?>
<!-------------------- CODE Starts HERE -------------------->

    <div class="card w-50 mx-auto my-4">
        <div class="card-header text-center bg-secondary bg-gradient py-3 h3 text-white">REGISTRATION</div>
        <div class="card-body bg-secondary-subtle">
            <p>First Name: &nbsp
                <label id="err_first_name" class="err">
                </label>
                <input type="text" id="first_name" class="form-control" />
            </p>

            <p>Last Name: &nbsp
                <label id="err_last_name" class="err">
                </label>
                <input type="text" id="last_name" class="form-control" />
            </p>

            <p>User Name: &nbsp
                <label id="err_user_name" class="err">
                </label>
                <input type="text" id="user_name" class="form-control" />
            </p>

            <p>E-mail: &nbsp
                <label id="err_email" class="err">
                </label>
                <input type="text" id="email" class="form-control" />
            </p>

            <p>Password: &nbsp
                <label id="err_password1" class="err">
                </label>
                <input type="password" id="password1" class="form-control" />
            </p>

            <p>Password (again):
                <input type="password" id="password2" class="form-control" />
            </p>

            <div>
                <input type="button" value="Submit" class="btn btn-success" />
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