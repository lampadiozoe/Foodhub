try:
    name = input("Enter student name: ")
    score = int(input("Enter score (0-100): "))

    if score < 0 or score > 100:
        print("Invalid score! Must be between 0 and 100.")

    else:
        if score >= 75:
            remarks = "Pass"
        else:
            remarks = "Fail"

        print("\nStudent:", name)
        print("Score:", score)
        print("Remarks:", remarks)

except:
    print("Invalid input! Please enter a number only.")

finally:
    print("End of Program.")